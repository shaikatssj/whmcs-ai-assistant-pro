<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Ensure the agent actions audit table exists (safe for upgrades without re-activation).
 */
function neko_ensure_agent_tables()
{
    try {
        if (!Capsule::schema()->hasTable('mod_gemini_agent_actions')) {
            Capsule::schema()->create('mod_gemini_agent_actions', function ($table) {
                $table->increments('id');
                $table->integer('ticket_id')->unsigned()->index();
                $table->integer('client_id')->unsigned()->default(0);
                $table->string('tool_name', 80);
                $table->text('tool_args')->nullable();
                $table->mediumText('tool_result')->nullable();
                $table->string('action_type', 20)->default('read'); // read, action, escalate
                $table->string('status', 20)->default('success'); // success, blocked, error
                $table->string('triggered_by', 30)->default('autopilot'); // autopilot, admin_assist, admin_solve
                $table->timestamp('created_at')->useCurrent();
            });
        }
    } catch (\Exception $e) {
        logActivity("Neko AI: Failed ensuring mod_gemini_agent_actions table - " . $e->getMessage());
    }
}

/**
 * Log an agent tool invocation for full auditability in the admin dashboard.
 */
function neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type = 'read', $status = 'success', $triggered_by = 'autopilot')
{
    try {
        neko_ensure_agent_tables();
        $result_json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($result_json) > 16000) {
            $result_json = substr($result_json, 0, 16000) . '... [truncated]';
        }
        Capsule::table('mod_gemini_agent_actions')->insert([
            'ticket_id' => (int) $ticket_id,
            'client_id' => (int) $client_id,
            'tool_name' => substr((string) $tool_name, 0, 80),
            'tool_args' => json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tool_result' => $result_json,
            'action_type' => $action_type,
            'status' => $status,
            'triggered_by' => $triggered_by,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Exception $e) {
        logActivity("Neko AI: Failed logging agent action {$tool_name}: " . $e->getMessage());
    }
}

/**
 * Get an active WHMCS admin username for localAPI calls.
 */
function neko_get_admin_username()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $admin = Capsule::table('tbladmins')->where('disabled', 0)->orderBy('id', 'asc')->first();
    $cached = $admin ? $admin->username : '';
    return $cached;
}

/**
 * Helper to execute localAPI safely with an active admin user.
 */
function neko_local_api($command, array $params = [])
{
    $admin_user = neko_get_admin_username();
    if (empty($admin_user)) {
        return ['result' => 'error', 'message' => 'No active WHMCS admin account available for localAPI.'];
    }
    return localAPI($command, $params, $admin_user);
}

/**
 * Return Gemini functionDeclarations based on enabled agent permissions and execution mode.
 *
 * Modes:
 * - 'autopilot'    : Full diagnostics + enabled safe/service write tools + escalate_to_human
 * - 'admin_solve'  : Admin explicitly clicked "Solve & Execute" -> same enabled tools
 * - 'admin_assist' : Admin clicked "Generate Reply (Preview)" -> read-only diagnostics only (no mutations!)
 */
function neko_get_agent_tool_declarations(array $config, $mode = 'autopilot')
{
    $allow_writes = in_array($mode, ['autopilot', 'admin_solve'], true);
    $allow_safe_actions = $allow_writes && !empty($config['agent_allow_safe_actions']);
    $allow_service_actions = $allow_writes && !empty($config['agent_allow_service_actions']);
    $allow_panel_checks = !empty($config['agent_allow_panel_checks']);

    $declarations = [
        [
            'name' => 'get_ticket_thread',
            'description' => 'Fetch the complete current ticket details, original message, full chronological reply history (client and staff/AI), custom fields, and internal admin notes.',
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
        ],
        [
            'name' => 'get_client_overview',
            'description' => 'Fetch the current customer profile, account status, credit balance, currency, and ticket counts.',
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
        ],
        [
            'name' => 'get_client_services',
            'description' => 'List ALL hosting/VPS/product services belonging to this customer (Active, Suspended with suspendreason, Pending, Terminated, Cancelled) along with domain, username, dedicated IP, assigned server IP/hostname, and product type.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status_filter' => [
                        'type' => 'string',
                        'description' => 'Optional filter: all, Active, Suspended, Pending, Terminated, Cancelled. Defaults to all.',
                    ],
                ],
            ],
        ],
        [
            'name' => 'get_client_invoices',
            'description' => 'List invoices belonging to this customer with line items, status (Unpaid, Paid, Overdue, Cancelled), due dates, totals, and payment method.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status_filter' => [
                        'type' => 'string',
                        'description' => 'Optional filter: all, Unpaid, Paid, Cancelled, Refunded. Defaults to all.',
                    ],
                ],
            ],
        ],
        [
            'name' => 'get_client_orders',
            'description' => 'List recent orders for this customer with order status, invoice ID, and amount.',
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
        ],
        [
            'name' => 'get_client_domains',
            'description' => 'List all domains belonging to this customer including registrar, status, expiry date, next due date, ID protection, and auto-renew settings.',
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
        ],
        [
            'name' => 'get_domain_registrar_info',
            'description' => 'Query live registrar nameservers and registrar lock status for a specific domain owned by this customer.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'domain_id' => [
                        'type' => 'integer',
                        'description' => 'The WHMCS tbldomains.id of the customer domain.',
                    ],
                ],
                'required' => ['domain_id'],
            ],
        ],
        [
            'name' => 'get_module_and_activity_errors',
            'description' => 'Check recent WHMCS module logs, failed provisioning/suspension queue items, and client activity logs to diagnose why an automated task or provisioning failed.',
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
        ],
        [
            'name' => 'get_client_emails',
            'description' => 'List recent email subjects and timestamps sent by WHMCS to this customer (e.g., Welcome Email, Invoice Created, Password Reset).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Number of recent emails to fetch (1-20, default 10).',
                    ],
                ],
            ],
        ],
        [
            'name' => 'check_domain_dns',
            'description' => 'Run live DNS lookups (A, AAAA, NS, MX, TXT, CNAME) for a domain name and compare against the assigned WHMCS server IP.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'domain' => [
                        'type' => 'string',
                        'description' => 'Domain name to inspect (e.g. example.com).',
                    ],
                ],
                'required' => ['domain'],
            ],
        ],
        [
            'name' => 'check_website_http_ssl',
            'description' => 'Check live HTTP/HTTPS response status code, redirect target, response time, and SSL/TLS certificate validity & expiry for a customer domain.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'domain' => [
                        'type' => 'string',
                        'description' => 'Domain or hostname to check (e.g. example.com).',
                    ],
                ],
                'required' => ['domain'],
            ],
        ],
        [
            'name' => 'search_knowledgebase',
            'description' => 'Search custom Q&A pairs and official WHMCS Knowledge Base articles for troubleshooting steps or company policies.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Keywords describing the issue (e.g. nameservers, ssl, email port, refund).',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
        [
            'name' => 'search_similar_tickets',
            'description' => 'Search past resolved tickets in the same support department matching keywords to see how staff previously solved similar issues.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Keywords from the current issue.',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ];

    if ($allow_panel_checks) {
        $declarations[] = [
            'name' => 'check_server_account_status',
            'description' => 'Query the live hosting control panel (cPanel/WHM or DirectAdmin) configured on the service WHMCS server to inspect real disk usage, bandwidth, suspension status, and account health (read-only).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'service_id' => [
                        'type' => 'integer',
                        'description' => 'The WHMCS tblhosting.id of the service.',
                    ],
                ],
                'required' => ['service_id'],
            ],
        ];
    }

    if ($allow_safe_actions) {
        $declarations[] = [
            'name' => 'resend_client_email',
            'description' => 'Resend a standard WHMCS email template to the customer (e.g., Hosting Account Welcome Email for a service_id, or Invoice Created / Invoice Payment Reminder for an invoice_id).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'messagename' => [
                        'type' => 'string',
                        'description' => 'Exact WHMCS email template name, e.g. "Hosting Account Welcome Email", "Invoice Created", "Invoice Payment Reminder", "Order Confirmation".',
                    ],
                    'rel_id' => [
                        'type' => 'integer',
                        'description' => 'The related service_id, invoice_id, or order_id owned by this customer.',
                    ],
                    'type' => [
                        'type' => 'string',
                        'description' => 'Related entity type: service, invoice, domain, or order.',
                    ],
                ],
                'required' => ['messagename', 'rel_id', 'type'],
            ],
        ];
        $declarations[] = [
            'name' => 'apply_credit_to_invoice',
            'description' => 'Apply the customer available WHMCS account credit to pay an unpaid invoice when the customer explicitly asks to pay with credit and has sufficient credit balance.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'invoice_id' => [
                        'type' => 'integer',
                        'description' => 'Unpaid invoice ID owned by this customer.',
                    ],
                    'amount' => [
                        'type' => 'number',
                        'description' => 'Amount of credit to apply.',
                    ],
                ],
                'required' => ['invoice_id', 'amount'],
            ],
        ];
        $declarations[] = [
            'name' => 'update_domain_nameservers',
            'description' => 'Update nameservers at the registrar for a domain owned by this customer ONLY when the customer explicitly requested changing them.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'domain_id' => ['type' => 'integer'],
                    'ns1' => ['type' => 'string'],
                    'ns2' => ['type' => 'string'],
                    'ns3' => ['type' => 'string'],
                    'ns4' => ['type' => 'string'],
                ],
                'required' => ['domain_id', 'ns1', 'ns2'],
            ],
        ];
        $declarations[] = [
            'name' => 'update_domain_lock',
            'description' => 'Enable or disable registrar transfer lock on a domain owned by this customer when explicitly requested.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'domain_id' => ['type' => 'integer'],
                    'locked' => [
                        'type' => 'boolean',
                        'description' => 'true to lock, false to unlock.',
                    ],
                ],
                'required' => ['domain_id', 'locked'],
            ],
        ];
        $declarations[] = [
            'name' => 'request_domain_epp',
            'description' => 'Request the EPP/Auth transfer code for a domain owned by this customer when explicitly requested.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'domain_id' => ['type' => 'integer'],
                ],
                'required' => ['domain_id'],
            ],
        ];
        $declarations[] = [
            'name' => 'update_ticket_metadata',
            'description' => 'Update current ticket department, priority, or status (e.g., move billing ticket to Billing department or raise priority).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'deptid' => ['type' => 'integer', 'description' => 'Optional target department ID.'],
                    'priority' => ['type' => 'string', 'description' => 'Optional priority: Low, Medium, High.'],
                ],
            ],
        ];
    }

    if ($allow_service_actions) {
        $declarations[] = [
            'name' => 'unsuspend_paid_service',
            'description' => 'Unsuspend a Suspended hosting/VPS service ONLY after verifying there are NO unpaid invoices for that service.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'service_id' => ['type' => 'integer'],
                ],
                'required' => ['service_id'],
            ],
        ];
        $declarations[] = [
            'name' => 'provision_paid_pending_service',
            'description' => 'Run ModuleCreate on a Pending service ONLY after verifying its order/invoice is Paid.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'service_id' => ['type' => 'integer'],
                ],
                'required' => ['service_id'],
            ],
        ];
        $declarations[] = [
            'name' => 'accept_paid_order',
            'description' => 'Accept and auto-provision a Pending order ONLY after verifying its invoice is Paid (or amount is 0.00).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'order_id' => ['type' => 'integer'],
                ],
                'required' => ['order_id'],
            ],
        ];
        $declarations[] = [
            'name' => 'run_vps_power_action',
            'description' => 'Run a whitelisted power action (reboot, boot, shutdown) via ModuleCustom on a VPS service ONLY when explicitly requested by the customer.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'service_id' => ['type' => 'integer'],
                    'action' => [
                        'type' => 'string',
                        'description' => 'One of: reboot, boot, shutdown',
                    ],
                ],
                'required' => ['service_id', 'action'],
            ],
        ];
    }

    if ($allow_writes) {
        $declarations[] = [
            'name' => 'escalate_to_human',
            'description' => 'Escalate this ticket to a human engineer/admin with a detailed diagnostic note whenever root/SSH access, manual billing/refund, abuse review, or an unresolvable/high-risk issue is needed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Short clear reason why human intervention is required.',
                    ],
                    'diagnostic_summary' => [
                        'type' => 'string',
                        'description' => 'Everything you already verified (service status, DNS/HTTP, invoices, module errors) and recommended next step for staff.',
                    ],
                ],
                'required' => ['reason', 'diagnostic_summary'],
            ],
        ];
    }

    return $declarations;
}

/**
 * Execute an agent tool strictly scoped to the ticket & client in $context.
 */
function neko_execute_agent_tool($tool_name, array $args, array $context)
{
    $ticket_id = (int) ($context['ticket_id'] ?? 0);
    $client_id = (int) ($context['client_id'] ?? 0);
    $config = $context['config'] ?? [];
    $mode = $context['mode'] ?? 'autopilot';

    $allow_writes = in_array($mode, ['autopilot', 'admin_solve'], true);
    $allow_safe_actions = $allow_writes && !empty($config['agent_allow_safe_actions']);
    $allow_service_actions = $allow_writes && !empty($config['agent_allow_service_actions']);
    $allow_panel_checks = !empty($config['agent_allow_panel_checks']);

    $action_type = 'read';
    $result = null;

    try {
        switch ($tool_name) {
            case 'get_ticket_thread':
                $result = neko_tool_get_ticket_thread($ticket_id, $config);
                break;

            case 'get_client_overview':
                $result = neko_tool_get_client_overview($client_id);
                break;

            case 'get_client_services':
                $result = neko_tool_get_client_services($client_id, $args['status_filter'] ?? 'all');
                break;

            case 'get_client_invoices':
                $result = neko_tool_get_client_invoices($client_id, $args['status_filter'] ?? 'all');
                break;

            case 'get_client_orders':
                $result = neko_tool_get_client_orders($client_id);
                break;

            case 'get_client_domains':
                $result = neko_tool_get_client_domains($client_id);
                break;

            case 'get_domain_registrar_info':
                $result = neko_tool_get_domain_registrar_info($client_id, (int) ($args['domain_id'] ?? 0));
                break;

            case 'get_module_and_activity_errors':
                $result = neko_tool_get_module_and_activity_errors($client_id);
                break;

            case 'get_client_emails':
                $result = neko_tool_get_client_emails($client_id, (int) ($args['limit'] ?? 10));
                break;

            case 'check_domain_dns':
                $result = neko_tool_check_domain_dns((string) ($args['domain'] ?? ''), $client_id);
                break;

            case 'check_website_http_ssl':
                $result = neko_tool_check_website_http_ssl((string) ($args['domain'] ?? ''));
                break;

            case 'search_knowledgebase':
                $result = neko_tool_search_knowledgebase((string) ($args['query'] ?? ''));
                break;

            case 'search_similar_tickets':
                $result = neko_tool_search_similar_tickets($ticket_id, (string) ($args['query'] ?? ''));
                break;

            case 'check_server_account_status':
                if (!$allow_panel_checks) {
                    $result = ['ok' => false, 'error' => 'Live control panel checks are disabled in module settings.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, 'read', 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_check_server_account_status($client_id, (int) ($args['service_id'] ?? 0));
                break;

            // --- Level 1 Safe Actions ---
            case 'resend_client_email':
                $action_type = 'action';
                if (!$allow_safe_actions) {
                    $result = ['ok' => false, 'error' => 'Safe actions are disabled or running in read-only preview mode.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_resend_client_email($client_id, $args);
                break;

            case 'apply_credit_to_invoice':
                $action_type = 'action';
                if (!$allow_safe_actions) {
                    $result = ['ok' => false, 'error' => 'Safe actions are disabled or running in read-only preview mode.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_apply_credit_to_invoice($client_id, (int) ($args['invoice_id'] ?? 0), (float) ($args['amount'] ?? 0));
                break;

            case 'update_domain_nameservers':
                $action_type = 'action';
                if (!$allow_safe_actions) {
                    $result = ['ok' => false, 'error' => 'Safe actions are disabled or running in read-only preview mode.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_update_domain_nameservers($client_id, $args);
                break;

            case 'update_domain_lock':
                $action_type = 'action';
                if (!$allow_safe_actions) {
                    $result = ['ok' => false, 'error' => 'Safe actions are disabled or running in read-only preview mode.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_update_domain_lock($client_id, (int) ($args['domain_id'] ?? 0), !empty($args['locked']));
                break;

            case 'request_domain_epp':
                $action_type = 'action';
                if (!$allow_safe_actions) {
                    $result = ['ok' => false, 'error' => 'Safe actions are disabled or running in read-only preview mode.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_request_domain_epp($client_id, (int) ($args['domain_id'] ?? 0));
                break;

            case 'update_ticket_metadata':
                $action_type = 'action';
                if (!$allow_safe_actions) {
                    $result = ['ok' => false, 'error' => 'Safe actions are disabled or running in read-only preview mode.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_update_ticket_metadata($ticket_id, $args);
                break;

            // --- Level 2 Service / Provisioning Actions ---
            case 'unsuspend_paid_service':
                $action_type = 'action';
                if (!$allow_service_actions) {
                    $result = ['ok' => false, 'error' => 'Service provisioning/unsuspend actions are disabled in module settings or preview mode.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_unsuspend_paid_service($client_id, (int) ($args['service_id'] ?? 0));
                break;

            case 'provision_paid_pending_service':
                $action_type = 'action';
                if (!$allow_service_actions) {
                    $result = ['ok' => false, 'error' => 'Service provisioning actions are disabled in module settings or preview mode.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_provision_paid_pending_service($client_id, (int) ($args['service_id'] ?? 0));
                break;

            case 'accept_paid_order':
                $action_type = 'action';
                if (!$allow_service_actions) {
                    $result = ['ok' => false, 'error' => 'Order acceptance actions are disabled in module settings or preview mode.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_accept_paid_order($client_id, (int) ($args['order_id'] ?? 0));
                break;

            case 'run_vps_power_action':
                $action_type = 'action';
                if (!$allow_service_actions) {
                    $result = ['ok' => false, 'error' => 'Service power actions are disabled in module settings or preview mode.'];
                    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'blocked', $mode);
                    return $result;
                }
                $result = neko_tool_run_vps_power_action($client_id, (int) ($args['service_id'] ?? 0), (string) ($args['action'] ?? ''));
                break;

            // --- Escalation ---
            case 'escalate_to_human':
                $action_type = 'escalate';
                if (!$allow_writes) {
                    $result = ['ok' => true, 'preview_only' => true, 'note' => 'Escalation recommended (preview mode, no changes applied).'];
                } else {
                    $reason = trim((string) ($args['reason'] ?? 'AI Agent escalated ticket to human support'));
                    $diag = trim((string) ($args['diagnostic_summary'] ?? ''));
                    $result = neko_tool_escalate_to_human($ticket_id, $client_id, $reason, $diag);
                }
                break;

            default:
                $result = ['ok' => false, 'error' => "Unknown tool: {$tool_name}"];
                neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'error', $mode);
                return $result;
        }
    } catch (\Exception $e) {
        $result = ['ok' => false, 'error' => $e->getMessage()];
        neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, 'error', $mode);
        return $result;
    }

    $status = (!empty($result['ok'])) ? 'success' : 'error';
    neko_log_agent_action($ticket_id, $client_id, $tool_name, $args, $result, $action_type, $status, $mode);
    return $result;
}

/* ============================================================================
 * READ / DIAGNOSTIC TOOL IMPLEMENTATIONS
 * ============================================================================ */

function neko_tool_get_ticket_thread($ticket_id, array $config)
{
    $ticket = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
    if (!$ticket) {
        return ['ok' => false, 'error' => 'Ticket not found'];
    }

    $department = Capsule::table('tblticketdepartments')->where('id', $ticket->did)->value('name') ?? 'General';
    $all_departments = Capsule::table('tblticketdepartments')->select('id', 'name')->get()->toArray();

    $replies = Capsule::table('tblticketreplies')
        ->where('tid', $ticket_id)
        ->orderBy('id', 'asc')
        ->get();

    $thread = [
        [
            'role' => 'customer',
            'author' => $ticket->name ?: 'Customer',
            'date' => $ticket->date,
            'message' => trim((string) $ticket->message),
        ]
    ];

    foreach ($replies as $r) {
        $is_staff = !empty($r->admin);
        $thread[] = [
            'role' => $is_staff ? 'staff_or_ai' : 'customer',
            'author' => $is_staff ? $r->admin : ($r->name ?: 'Customer'),
            'date' => $r->date,
            'message' => trim((string) $r->message),
        ];
    }

    $custom_fields = [];
    try {
        $cfs = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
            ->where('tblcustomfields.type', 'support')
            ->where('tblcustomfieldsvalues.relid', $ticket_id)
            ->select('tblcustomfields.fieldname', 'tblcustomfieldsvalues.value')
            ->get();
        foreach ($cfs as $cf) {
            if ($cf->value !== '') {
                $custom_fields[$cf->fieldname] = $cf->value;
            }
        }
    } catch (\Exception $e) {}

    $admin_notes = [];
    if (!empty($config['include_admin_notes'])) {
        try {
            $notes = Capsule::table('tblticketnotes')
                ->where('ticketid', $ticket_id)
                ->orderBy('date', 'asc')
                ->get();
            foreach ($notes as $n) {
                $admin_notes[] = [
                    'date' => $n->date,
                    'admin' => $n->admin,
                    'message' => trim(strip_tags((string) $n->message)),
                ];
            }
        } catch (\Exception $e) {}
    }

    return [
        'ok' => true,
        'ticket' => [
            'id' => (int) $ticket->id,
            'tid' => $ticket->tid,
            'subject' => $ticket->title,
            'status' => $ticket->status,
            'urgency' => $ticket->urgency,
            'department_id' => (int) $ticket->did,
            'department_name' => $department,
            'linked_service_id' => (int) ($ticket->service ?? 0),
            'linked_domain_id' => (int) ($ticket->domainid ?? 0),
            'created_at' => $ticket->date,
            'last_reply_at' => $ticket->lastreply,
        ],
        'available_departments' => $all_departments,
        'custom_fields' => $custom_fields,
        'admin_notes' => $admin_notes,
        'thread' => $thread,
    ];
}

function neko_tool_get_client_overview($client_id)
{
    if ($client_id <= 0) {
        return ['ok' => true, 'registered_client' => false, 'note' => 'Guest / unregistered ticket sender (no WHMCS client account linked).'];
    }

    $client = Capsule::table('tblclients')->where('id', $client_id)->first();
    if (!$client) {
        return ['ok' => false, 'error' => 'Client not found'];
    }

    $currency = Capsule::table('tblcurrencies')->where('id', $client->currency)->first();
    $paid_total = (float) Capsule::table('tblinvoices')->where('userid', $client_id)->where('status', 'Paid')->sum('total');
    $unpaid_count = Capsule::table('tblinvoices')->where('userid', $client_id)->where('status', 'Unpaid')->count();
    $total_tickets = Capsule::table('tbltickets')->where('userid', $client_id)->count();

    return [
        'ok' => true,
        'registered_client' => true,
        'client_id' => (int) $client->id,
        'name' => trim($client->firstname . ' ' . $client->lastname),
        'email' => $client->email,
        'company' => $client->companyname,
        'status' => $client->status,
        'country' => $client->country,
        'language' => $client->language ?: 'english',
        'credit_balance' => (float) $client->credit,
        'currency_code' => $currency ? $currency->code : 'USD',
        'currency_prefix' => $currency ? $currency->prefix : '$',
        'total_spent' => round($paid_total, 2),
        'unpaid_invoices_count' => $unpaid_count,
        'total_tickets' => $total_tickets,
        'customer_since' => $client->datecreated,
    ];
}

function neko_tool_get_client_services($client_id, $status_filter = 'all')
{
    if ($client_id <= 0) {
        return ['ok' => true, 'services' => [], 'note' => 'Unregistered guest ticket.'];
    }

    $query = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
        ->leftJoin('tblservers', 'tblhosting.server', '=', 'tblservers.id')
        ->where('tblhosting.userid', $client_id);

    if (!empty($status_filter) && strtolower($status_filter) !== 'all') {
        $query->where('tblhosting.domainstatus', $status_filter);
    }

    $rows = $query->select(
        'tblhosting.id as service_id',
        'tblhosting.orderid',
        'tblhosting.domain',
        'tblhosting.username',
        'tblhosting.domainstatus as status',
        'tblhosting.suspendreason',
        'tblhosting.dedicatedip',
        'tblhosting.assignedips',
        'tblhosting.nextduedate',
        'tblhosting.billingcycle',
        'tblhosting.amount',
        'tblhosting.regdate',
        'tblproducts.name as product_name',
        'tblproducts.type as product_type',
        'tblproducts.servertype',
        'tblservers.id as server_id',
        'tblservers.name as server_name',
        'tblservers.ipaddress as server_ip',
        'tblservers.hostname as server_hostname',
        'tblservers.nameserver1',
        'tblservers.nameserver2',
        'tblservers.nameserver3',
        'tblservers.nameserver4'
    )->orderBy('tblhosting.id', 'desc')->get();

    $services = [];
    foreach ($rows as $r) {
        $services[] = [
            'service_id' => (int) $r->service_id,
            'order_id' => (int) $r->orderid,
            'product_name' => $r->product_name,
            'product_type' => $r->product_type,
            'server_module' => $r->servertype,
            'domain' => $r->domain,
            'username' => $r->username,
            'status' => $r->status,
            'suspend_reason' => $r->suspendreason,
            'dedicated_ip' => $r->dedicatedip,
            'assigned_ips' => $r->assignedips,
            'next_due_date' => $r->nextduedate,
            'billing_cycle' => $r->billingcycle,
            'amount' => $r->amount,
            'server' => [
                'name' => $r->server_name,
                'ip' => $r->server_ip,
                'hostname' => $r->server_hostname,
                'nameservers' => array_values(array_filter([$r->nameserver1, $r->nameserver2, $r->nameserver3, $r->nameserver4])),
            ],
        ];
    }

    return ['ok' => true, 'count' => count($services), 'services' => $services];
}

function neko_tool_get_client_invoices($client_id, $status_filter = 'all')
{
    if ($client_id <= 0) {
        return ['ok' => true, 'invoices' => [], 'note' => 'Unregistered guest ticket.'];
    }

    $query = Capsule::table('tblinvoices')->where('userid', $client_id);
    if (!empty($status_filter) && strtolower($status_filter) !== 'all') {
        $query->where('status', $status_filter);
    }

    $rows = $query->orderBy('id', 'desc')->limit(15)->get();
    $invoices = [];

    foreach ($rows as $inv) {
        $items = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $inv->id)
            ->select('type', 'relid', 'description', 'amount')
            ->get()
            ->toArray();

        $invoices[] = [
            'invoice_id' => (int) $inv->id,
            'invoice_num' => $inv->invoicenum ?: (string) $inv->id,
            'status' => $inv->status,
            'date' => $inv->date,
            'due_date' => $inv->duedate,
            'date_paid' => $inv->datepaid,
            'subtotal' => $inv->subtotal,
            'credit_applied' => $inv->credit,
            'total' => $inv->total,
            'payment_method' => $inv->paymentmethod,
            'is_overdue' => ($inv->status === 'Unpaid' && strtotime($inv->duedate) < strtotime(date('Y-m-d'))),
            'items' => $items,
        ];
    }

    return ['ok' => true, 'count' => count($invoices), 'invoices' => $invoices];
}

function neko_tool_get_client_orders($client_id)
{
    if ($client_id <= 0) {
        return ['ok' => true, 'orders' => []];
    }

    $orders = Capsule::table('tblorders')
        ->where('userid', $client_id)
        ->orderBy('id', 'desc')
        ->limit(15)
        ->select('id', 'ordernum', 'date', 'amount', 'status', 'invoiceid', 'paymentmethod')
        ->get()
        ->toArray();

    return ['ok' => true, 'count' => count($orders), 'orders' => $orders];
}

function neko_tool_get_client_domains($client_id)
{
    if ($client_id <= 0) {
        return ['ok' => true, 'domains' => []];
    }

    $domains = Capsule::table('tbldomains')
        ->where('userid', $client_id)
        ->orderBy('id', 'desc')
        ->select('id as domain_id', 'domain', 'registrar', 'status', 'registrationdate', 'expirydate', 'nextduedate', 'donotrenew', 'idprotection', 'dnsmanagement', 'emailforwarding')
        ->get()
        ->toArray();

    return ['ok' => true, 'count' => count($domains), 'domains' => $domains];
}

function neko_tool_get_domain_registrar_info($client_id, $domain_id)
{
    $domain = Capsule::table('tbldomains')
        ->where('id', $domain_id)
        ->where('userid', $client_id)
        ->first();

    if (!$domain) {
        return ['ok' => false, 'error' => 'Domain not found for this client.'];
    }

    $ns = neko_local_api('DomainGetNameservers', ['domainid' => $domain_id]);
    $lock = neko_local_api('DomainGetLockingStatus', ['domainid' => $domain_id]);

    return [
        'ok' => true,
        'domain' => $domain->domain,
        'registrar' => $domain->registrar,
        'status' => $domain->status,
        'nameservers' => ($ns['result'] ?? '') === 'success' ? array_filter([
            'ns1' => $ns['ns1'] ?? '',
            'ns2' => $ns['ns2'] ?? '',
            'ns3' => $ns['ns3'] ?? '',
            'ns4' => $ns['ns4'] ?? '',
            'ns5' => $ns['ns5'] ?? '',
        ]) : ['error' => $ns['message'] ?? 'Unable to fetch nameservers'],
        'lock_status' => ($lock['result'] ?? '') === 'success' ? ($lock['lockstatus'] ?? 'unknown') : ($lock['message'] ?? 'unknown'),
    ];
}

function neko_tool_get_module_and_activity_errors($client_id)
{
    $services = $client_id > 0
        ? Capsule::table('tblhosting')->where('userid', $client_id)->select('id', 'domain', 'username')->get()
        : collect([]);

    $service_ids = $services->pluck('id')->toArray();
    $terms = [];
    foreach ($services as $s) {
        if (!empty($s->domain)) $terms[] = $s->domain;
        if (!empty($s->username)) $terms[] = $s->username;
    }

    $activity_logs = [];
    if ($client_id > 0) {
        try {
            $activity_logs = Capsule::table('tblactivitylog')
                ->where('userid', $client_id)
                ->orderBy('id', 'desc')
                ->limit(15)
                ->select('date', 'description', 'user')
                ->get()
                ->toArray();
        } catch (\Exception $e) {}
    }

    $module_logs = [];
    if (!empty($terms)) {
        try {
            $q = Capsule::table('tblmodulelog')->orderBy('id', 'desc');
            $q->where(function ($sub) use ($terms) {
                foreach (array_slice(array_unique($terms), 0, 6) as $t) {
                    $sub->orWhere('request', 'LIKE', '%' . $t . '%')
                        ->orWhere('response', 'LIKE', '%' . $t . '%');
                }
            });
            $rows = $q->limit(8)->select('date', 'module', 'action', 'request', 'response')->get();
            foreach ($rows as $row) {
                $module_logs[] = [
                    'date' => $row->date,
                    'module' => $row->module,
                    'action' => $row->action,
                    'response_excerpt' => substr(strip_tags((string) $row->response), 0, 400),
                ];
            }
        } catch (\Exception $e) {}
    }

    $queue_items = [];
    if (!empty($service_ids)) {
        $queue = neko_local_api('GetModuleQueue', []);
        if (($queue['result'] ?? '') === 'success' && !empty($queue['queue'])) {
            foreach ($queue['queue'] as $item) {
                if (in_array((int) ($item['service_id'] ?? 0), $service_ids, true)) {
                    $queue_items[] = $item;
                }
            }
        }
    }

    return [
        'ok' => true,
        'activity_logs' => $activity_logs,
        'module_logs' => $module_logs,
        'module_queue_failures' => $queue_items,
    ];
}

function neko_tool_get_client_emails($client_id, $limit = 10)
{
    if ($client_id <= 0) {
        return ['ok' => true, 'emails' => []];
    }
    $limit = max(1, min(20, (int) $limit));
    $emails = Capsule::table('tblemails')
        ->where('userid', $client_id)
        ->orderBy('id', 'desc')
        ->limit($limit)
        ->select('id', 'subject', 'date', 'to')
        ->get()
        ->toArray();

    return ['ok' => true, 'count' => count($emails), 'emails' => $emails];
}

function neko_sanitize_domain($domain)
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = explode('/', $domain)[0];
    $domain = explode(':', $domain)[0];
    $domain = rtrim($domain, '.');

    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
        return '';
    }
    return $domain;
}

function neko_is_public_ip($ip)
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function neko_tool_check_domain_dns($raw_domain, $client_id = 0)
{
    $domain = neko_sanitize_domain($raw_domain);
    if ($domain === '') {
        return ['ok' => false, 'error' => 'Invalid domain format.'];
    }

    $records = [];
    $types = [
        'A' => DNS_A,
        'AAAA' => DNS_AAAA,
        'NS' => DNS_NS,
        'MX' => DNS_MX,
        'TXT' => DNS_TXT,
        'CNAME' => DNS_CNAME,
    ];

    foreach ($types as $label => $flag) {
        $res = @dns_get_record($domain, $flag);
        if (is_array($res) && !empty($res)) {
            $records[$label] = array_map(function ($r) use ($label) {
                if ($label === 'A') return $r['ip'] ?? '';
                if ($label === 'AAAA') return $r['ipv6'] ?? '';
                if ($label === 'NS' || $label === 'CNAME') return $r['target'] ?? '';
                if ($label === 'MX') return ($r['pri'] ?? 0) . ' ' . ($r['target'] ?? '');
                if ($label === 'TXT') return $r['txt'] ?? '';
                return $r;
            }, $res);
        } else {
            $records[$label] = [];
        }
    }

    // Compare against client's WHMCS service server IP / nameservers if matched
    $expected = null;
    if ($client_id > 0) {
        $service = Capsule::table('tblhosting')
            ->leftJoin('tblservers', 'tblhosting.server', '=', 'tblservers.id')
            ->where('tblhosting.userid', $client_id)
            ->where('tblhosting.domain', $domain)
            ->select('tblhosting.dedicatedip', 'tblservers.ipaddress', 'tblservers.nameserver1', 'tblservers.nameserver2')
            ->first();
        if ($service) {
            $expected_ip = $service->dedicatedip ?: $service->ipaddress;
            $expected = [
                'expected_server_ip' => $expected_ip,
                'expected_nameservers' => array_values(array_filter([$service->nameserver1, $service->nameserver2])),
                'a_record_matches_server' => !empty($expected_ip) && in_array($expected_ip, $records['A'], true),
            ];
        }
    }

    return [
        'ok' => true,
        'domain' => $domain,
        'dns_records' => $records,
        'whmcs_comparison' => $expected,
    ];
}

function neko_tool_check_website_http_ssl($raw_domain)
{
    $domain = neko_sanitize_domain($raw_domain);
    if ($domain === '') {
        return ['ok' => false, 'error' => 'Invalid domain format.'];
    }

    $ips = @gethostbynamel($domain);
    if (empty($ips)) {
        return ['ok' => true, 'domain' => $domain, 'reachable' => false, 'error' => 'DNS resolution failed (no A record found).'];
    }

    foreach ($ips as $ip) {
        if (!neko_is_public_ip($ip)) {
            return ['ok' => false, 'error' => 'Refused to probe private or reserved IP address.'];
        }
    }

    // HTTP/HTTPS probe
    $http_info = [];
    foreach (['https', 'http'] as $scheme) {
        $url = "{$scheme}://{$domain}/";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'NekoAI-Diagnostics/1.0',
        ]);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $total_time = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 3);
        $err = curl_error($ch);
        curl_close($ch);

        $http_info[$scheme] = [
            'http_code' => $http_code,
            'redirect_url' => $redirect ?: null,
            'response_time_sec' => $total_time,
            'curl_error' => $err ?: null,
        ];
    }

    // TLS Certificate inspection
    $ssl_info = ['present' => false];
    $ctx = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $domain,
        ]
    ]);
    $socket = @stream_socket_client("ssl://{$domain}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
    if ($socket) {
        $params = stream_context_get_params($socket);
        if (!empty($params['options']['ssl']['peer_certificate'])) {
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
            if ($cert) {
                $valid_to = $cert['validTo_time_t'] ?? 0;
                $ssl_info = [
                    'present' => true,
                    'subject_cn' => $cert['subject']['CN'] ?? '',
                    'issuer' => $cert['issuer']['O'] ?? ($cert['issuer']['CN'] ?? ''),
                    'valid_from' => isset($cert['validFrom_time_t']) ? date('Y-m-d', $cert['validFrom_time_t']) : null,
                    'valid_to' => $valid_to ? date('Y-m-d', $valid_to) : null,
                    'is_expired' => $valid_to > 0 ? ($valid_to < time()) : null,
                    'san' => $cert['extensions']['subjectAltName'] ?? '',
                ];
            }
        }
        fclose($socket);
    } else {
        $ssl_info['error'] = $errstr ?: 'Unable to connect on port 443';
    }

    return [
        'ok' => true,
        'domain' => $domain,
        'resolved_ips' => $ips,
        'http_checks' => $http_info,
        'ssl_certificate' => $ssl_info,
    ];
}

function neko_tool_check_server_account_status($client_id, $service_id)
{
    $service = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
        ->join('tblservers', 'tblhosting.server', '=', 'tblservers.id')
        ->where('tblhosting.id', $service_id)
        ->where('tblhosting.userid', $client_id)
        ->select(
            'tblhosting.id',
            'tblhosting.username',
            'tblhosting.domain',
            'tblhosting.domainstatus',
            'tblproducts.servertype',
            'tblservers.ipaddress',
            'tblservers.hostname',
            'tblservers.username as srv_user',
            'tblservers.password as srv_pass',
            'tblservers.accesshash',
            'tblservers.secure',
            'tblservers.port'
        )
        ->first();

    if (!$service) {
        return ['ok' => false, 'error' => 'Service or server configuration not found for this client.'];
    }
    if (empty($service->username)) {
        return ['ok' => false, 'error' => 'Service has no server username assigned yet.'];
    }

    $host = $service->hostname ?: $service->ipaddress;
    $type = strtolower((string) $service->servertype);
    $scheme = $service->secure ? 'https' : 'http';

    $decrypted_pass = '';
    if (!empty($service->srv_pass)) {
        $dec = neko_local_api('DecryptPassword', ['password2' => $service->srv_pass]);
        $decrypted_pass = $dec['password'] ?? '';
    }

    // cPanel / WHM JSON API (accountsummary)
    if (strpos($type, 'cpanel') !== false) {
        $port = $service->port ?: ($service->secure ? 2087 : 2086);
        $url = "{$scheme}://{$host}:{$port}/json-api/accountsummary?api.version=1&user=" . urlencode($service->username);

        $headers = [];
        if (!empty($service->accesshash)) {
            $token = preg_replace("/\r|\n/", '', $service->accesshash);
            $headers[] = "Authorization: whm {$service->srv_user}:{$token}";
        } elseif ($decrypted_pass !== '') {
            $headers[] = "Authorization: Basic " . base64_encode("{$service->srv_user}:{$decrypted_pass}");
        } else {
            return ['ok' => false, 'error' => 'No WHM API token or password available on tblservers.'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'error' => "WHM connection failed: {$err}"];
        }
        $data = json_decode($resp, true);
        if (!empty($data['data']['acct'][0])) {
            $acct = $data['data']['acct'][0];
            return [
                'ok' => true,
                'panel' => 'cpanel_whm',
                'username' => $acct['user'] ?? $service->username,
                'domain' => $acct['domain'] ?? $service->domain,
                'suspended' => !empty($acct['suspended']),
                'suspend_reason' => $acct['suspendreason'] ?? '',
                'disk_used' => $acct['diskused'] ?? '',
                'disk_limit' => $acct['disklimit'] ?? '',
                'inodes_used' => $acct['inodesused'] ?? '',
                'inodes_limit' => $acct['inodeslimit'] ?? '',
                'plan' => $acct['plan'] ?? '',
                'ip' => $acct['ip'] ?? '',
            ];
        }
        return ['ok' => false, 'error' => 'WHM API did not return account summary for ' . $service->username];
    }

    // DirectAdmin CMD_API_SHOW_USER_USAGE / CONFIG
    if (strpos($type, 'directadmin') !== false) {
        $port = $service->port ?: 2222;
        if ($decrypted_pass === '') {
            return ['ok' => false, 'error' => 'No DirectAdmin password configured on tblservers.'];
        }
        $url = "{$scheme}://{$host}:{$port}/CMD_API_SHOW_USER_CONFIG?json=yes&user=" . urlencode($service->username);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$service->srv_user}:{$decrypted_pass}",
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'error' => "DirectAdmin connection failed: {$err}"];
        }
        $data = json_decode($resp, true);
        if (is_array($data) && empty($data['error'])) {
            return [
                'ok' => true,
                'panel' => 'directadmin',
                'username' => $service->username,
                'suspended' => ($data['suspended'] ?? 'no') === 'yes',
                'package' => $data['package'] ?? '',
                'bandwidth_limit' => $data['bandwidth'] ?? '',
                'quota_limit' => $data['quota'] ?? '',
                'ip' => $data['ip'] ?? '',
            ];
        }
        return ['ok' => false, 'error' => $data['text'] ?? 'Unable to read DirectAdmin account config.'];
    }

    return ['ok' => false, 'error' => "Live panel inspection is not implemented for server module '{$service->servertype}'."];
}

function neko_tool_search_knowledgebase($query)
{
    $words = str_word_count(strtolower(strip_tags($query)), 1);
    $stop = ['please', 'thanks', 'hello', 'there', 'would', 'could', 'should', 'about', 'which', 'issue', 'error', 'problem', 'help', 'with', 'this', 'that', 'have', 'from', 'your'];
    $keywords = [];
    foreach ($words as $w) {
        if (strlen($w) >= 3 && !in_array($w, $stop, true)) {
            $keywords[] = $w;
        }
    }
    $keywords = array_slice(array_unique($keywords), 0, 6);

    $qa_results = [];
    $kb_results = [];

    $qa_query = Capsule::table('mod_gemini_qa_pairs')->where('active', true);
    if (!empty($keywords)) {
        $qa_query->where(function ($q) use ($keywords) {
            foreach ($keywords as $kw) {
                $q->orWhere('question', 'LIKE', "%{$kw}%")
                  ->orWhere('answer', 'LIKE', "%{$kw}%");
            }
        });
    }
    foreach ($qa_query->orderBy('priority', 'desc')->limit(5)->get() as $qa) {
        $qa_results[] = ['question' => $qa->question, 'answer' => $qa->answer];
    }

    try {
        $kb_query = Capsule::table('tblknowledgebase');
        if (!empty($keywords)) {
            $kb_query->where(function ($q) use ($keywords) {
                foreach ($keywords as $kw) {
                    $q->orWhere('title', 'LIKE', "%{$kw}%")
                      ->orWhere('article', 'LIKE', "%{$kw}%");
                }
            });
        }
        foreach ($kb_query->orderBy('views', 'desc')->limit(4)->get() as $art) {
            $kb_results[] = [
                'title' => $art->title,
                'content' => substr(trim(strip_tags((string) $art->article)), 0, 1200),
            ];
        }
    } catch (\Exception $e) {}

    return [
        'ok' => true,
        'keywords_used' => $keywords,
        'qa_pairs' => $qa_results,
        'kb_articles' => $kb_results,
    ];
}

function neko_tool_search_similar_tickets($current_ticket_id, $query)
{
    $ticket = Capsule::table('tbltickets')->where('id', $current_ticket_id)->first();
    if (!$ticket) {
        return ['ok' => true, 'examples' => []];
    }

    $words = str_word_count(strtolower(strip_tags($query)), 1);
    $keywords = array_slice(array_values(array_unique(array_filter($words, function ($w) {
        return strlen($w) >= 4;
    }))), 0, 5);

    $examples = [];
    try {
        $q = Capsule::table('tbltickets')
            ->join('tblticketreplies', 'tbltickets.id', '=', 'tblticketreplies.tid')
            ->where('tbltickets.did', $ticket->did)
            ->where('tbltickets.id', '!=', $current_ticket_id)
            ->where('tblticketreplies.admin', '!=', '');

        if (!empty($keywords)) {
            $q->where(function ($sub) use ($keywords) {
                foreach ($keywords as $kw) {
                    $sub->orWhere('tbltickets.title', 'LIKE', "%{$kw}%")
                        ->orWhere('tbltickets.message', 'LIKE', "%{$kw}%");
                }
            });
        }

        $rows = $q->select('tbltickets.title', 'tbltickets.message as question', 'tblticketreplies.message as answer')
            ->orderBy('tbltickets.id', 'desc')
            ->limit(4)
            ->get();

        foreach ($rows as $ex) {
            $examples[] = [
                'subject' => $ex->title,
                'question_excerpt' => substr(strip_tags((string) $ex->question), 0, 250),
                'staff_reply_excerpt' => substr(strip_tags((string) $ex->answer), 0, 450),
            ];
        }
    } catch (\Exception $e) {}

    return ['ok' => true, 'examples' => $examples];
}

/* ============================================================================
 * SAFE & GUARDED ACTION TOOL IMPLEMENTATIONS
 * ============================================================================ */

function neko_tool_resend_client_email($client_id, array $args)
{
    $messagename = trim((string) ($args['messagename'] ?? ''));
    $rel_id = (int) ($args['rel_id'] ?? 0);
    $type = strtolower(trim((string) ($args['type'] ?? '')));

    $allowed_templates = [
        'Hosting Account Welcome Email',
        'Reseller Account Welcome Email',
        'Dedicated/VPS Server Welcome Email',
        'Invoice Created',
        'Invoice Payment Reminder',
        'First Invoice Overdue Notice',
        'Order Confirmation',
    ];

    if (!in_array($messagename, $allowed_templates, true)) {
        return ['ok' => false, 'error' => "Template '{$messagename}' is not in the whitelisted safe templates."];
    }

    // Verify ownership strictly
    $owned = false;
    if ($type === 'service') {
        $owned = Capsule::table('tblhosting')->where('id', $rel_id)->where('userid', $client_id)->exists();
    } elseif ($type === 'invoice') {
        $owned = Capsule::table('tblinvoices')->where('id', $rel_id)->where('userid', $client_id)->exists();
    } elseif ($type === 'order') {
        $owned = Capsule::table('tblorders')->where('id', $rel_id)->where('userid', $client_id)->exists();
    } elseif ($type === 'domain') {
        $owned = Capsule::table('tbldomains')->where('id', $rel_id)->where('userid', $client_id)->exists();
    }

    if (!$owned) {
        return ['ok' => false, 'error' => 'Target entity not found or does not belong to this client.'];
    }

    $res = neko_local_api('SendEmail', [
        'messagename' => $messagename,
        'id' => $rel_id,
    ]);

    return ($res['result'] ?? '') === 'success'
        ? ['ok' => true, 'message' => "Sent '{$messagename}' for {$type} #{$rel_id}."]
        : ['ok' => false, 'error' => $res['message'] ?? 'SendEmail failed'];
}

function neko_tool_apply_credit_to_invoice($client_id, $invoice_id, $amount)
{
    $client = Capsule::table('tblclients')->where('id', $client_id)->first();
    $invoice = Capsule::table('tblinvoices')->where('id', $invoice_id)->where('userid', $client_id)->first();

    if (!$client || !$invoice) {
        return ['ok' => false, 'error' => 'Invoice not found for this client.'];
    }
    if ($invoice->status !== 'Unpaid') {
        return ['ok' => false, 'error' => "Invoice #{$invoice_id} status is '{$invoice->status}', not Unpaid."];
    }

    $credit = (float) $client->credit;
    if ($credit <= 0 || $amount <= 0 || $amount > $credit) {
        return ['ok' => false, 'error' => "Insufficient credit balance ({$credit}) for amount {$amount}."];
    }

    $res = neko_local_api('ApplyCredit', [
        'invoiceid' => $invoice_id,
        'amount' => $amount,
    ]);

    return ($res['result'] ?? '') === 'success'
        ? ['ok' => true, 'message' => "Applied credit {$amount} to Invoice #{$invoice_id}.", 'invoicepaid' => $res['invoicepaid'] ?? null]
        : ['ok' => false, 'error' => $res['message'] ?? 'ApplyCredit failed'];
}

function neko_tool_update_domain_nameservers($client_id, array $args)
{
    $domain_id = (int) ($args['domain_id'] ?? 0);
    $domain = Capsule::table('tbldomains')->where('id', $domain_id)->where('userid', $client_id)->first();
    if (!$domain) {
        return ['ok' => false, 'error' => 'Domain not found for this client.'];
    }

    $payload = [
        'domainid' => $domain_id,
        'ns1' => trim((string) ($args['ns1'] ?? '')),
        'ns2' => trim((string) ($args['ns2'] ?? '')),
    ];
    foreach (['ns3', 'ns4', 'ns5'] as $k) {
        if (!empty($args[$k])) {
            $payload[$k] = trim((string) $args[$k]);
        }
    }

    $res = neko_local_api('DomainUpdateNameservers', $payload);
    return ($res['result'] ?? '') === 'success'
        ? ['ok' => true, 'message' => "Nameservers updated for {$domain->domain}."]
        : ['ok' => false, 'error' => $res['message'] ?? 'DomainUpdateNameservers failed'];
}

function neko_tool_update_domain_lock($client_id, $domain_id, $locked)
{
    $domain = Capsule::table('tbldomains')->where('id', $domain_id)->where('userid', $client_id)->first();
    if (!$domain) {
        return ['ok' => false, 'error' => 'Domain not found for this client.'];
    }

    $res = neko_local_api('DomainUpdateLockingStatus', [
        'domainid' => $domain_id,
        'lockstatus' => $locked ? 1 : 0,
    ]);

    return ($res['result'] ?? '') === 'success'
        ? ['ok' => true, 'message' => ($locked ? 'Locked' : 'Unlocked') . " domain {$domain->domain}."]
        : ['ok' => false, 'error' => $res['message'] ?? 'DomainUpdateLockingStatus failed'];
}

function neko_tool_request_domain_epp($client_id, $domain_id)
{
    $domain = Capsule::table('tbldomains')->where('id', $domain_id)->where('userid', $client_id)->first();
    if (!$domain) {
        return ['ok' => false, 'error' => 'Domain not found for this client.'];
    }

    $res = neko_local_api('DomainRequestEPP', ['domainid' => $domain_id]);
    return ($res['result'] ?? '') === 'success'
        ? ['ok' => true, 'domain' => $domain->domain, 'eppcode' => $res['eppcode'] ?? 'EPP code emailed to registrant/client.']
        : ['ok' => false, 'error' => $res['message'] ?? 'DomainRequestEPP failed'];
}

function neko_tool_update_ticket_metadata($ticket_id, array $args)
{
    $payload = ['ticketid' => $ticket_id];
    if (!empty($args['deptid'])) {
        $exists = Capsule::table('tblticketdepartments')->where('id', (int) $args['deptid'])->exists();
        if ($exists) {
            $payload['deptid'] = (int) $args['deptid'];
        }
    }
    if (!empty($args['priority']) && in_array($args['priority'], ['Low', 'Medium', 'High'], true)) {
        $payload['priority'] = $args['priority'];
    }
    if (count($payload) === 1) {
        return ['ok' => false, 'error' => 'No valid fields provided to update.'];
    }

    $res = neko_local_api('UpdateTicket', $payload);
    return ($res['result'] ?? '') === 'success'
        ? ['ok' => true, 'updated' => $payload]
        : ['ok' => false, 'error' => $res['message'] ?? 'UpdateTicket failed'];
}

function neko_service_has_unpaid_invoice($service_id, $client_id)
{
    $unpaid_item = Capsule::table('tblinvoiceitems')
        ->join('tblinvoices', 'tblinvoiceitems.invoiceid', '=', 'tblinvoices.id')
        ->where('tblinvoices.userid', $client_id)
        ->where('tblinvoices.status', 'Unpaid')
        ->where('tblinvoiceitems.relid', $service_id)
        ->whereIn('tblinvoiceitems.type', ['Hosting', 'Addon', 'Upgrade'])
        ->select('tblinvoices.id', 'tblinvoices.total', 'tblinvoices.duedate')
        ->first();

    return $unpaid_item ?: null;
}

function neko_tool_unsuspend_paid_service($client_id, $service_id)
{
    $service = Capsule::table('tblhosting')
        ->where('id', $service_id)
        ->where('userid', $client_id)
        ->first();

    if (!$service) {
        return ['ok' => false, 'error' => 'Service not found for this client.'];
    }
    if ($service->domainstatus !== 'Suspended') {
        return ['ok' => false, 'error' => "Service #{$service_id} status is '{$service->domainstatus}', not Suspended."];
    }

    $unpaid = neko_service_has_unpaid_invoice($service_id, $client_id);
    if ($unpaid) {
        return ['ok' => false, 'error' => "Cannot unsuspend: Invoice #{$unpaid->id} ({$unpaid->total}) is still Unpaid."];
    }

    // Never auto-unsuspend abuse/fraud/TOS suspensions without human review
    $reason_lower = strtolower((string) $service->suspendreason);
    foreach (['abuse', 'spam', 'phish', 'fraud', 'dmca', 'ddos', 'malware', 'tos', 'chargeback'] as $blocked) {
        if (strpos($reason_lower, $blocked) !== false) {
            return ['ok' => false, 'error' => "Cannot auto-unsuspend service suspended for '{$service->suspendreason}'. Human review required."];
        }
    }

    $res = neko_local_api('ModuleUnsuspend', ['serviceid' => $service_id]);
    return ($res['result'] ?? '') === 'success'
        ? ['ok' => true, 'message' => "Service #{$service_id} ({$service->domain}) successfully unsuspended."]
        : ['ok' => false, 'error' => $res['message'] ?? 'ModuleUnsuspend failed'];
}

function neko_tool_provision_paid_pending_service($client_id, $service_id)
{
    $service = Capsule::table('tblhosting')
        ->where('id', $service_id)
        ->where('userid', $client_id)
        ->first();

    if (!$service) {
        return ['ok' => false, 'error' => 'Service not found for this client.'];
    }
    if ($service->domainstatus !== 'Pending') {
        return ['ok' => false, 'error' => "Service #{$service_id} status is '{$service->domainstatus}', not Pending."];
    }

    if (!empty($service->orderid)) {
        $order = Capsule::table('tblorders')->where('id', $service->orderid)->first();
        if ($order && !empty($order->invoiceid)) {
            $invoice = Capsule::table('tblinvoices')->where('id', $order->invoiceid)->first();
            if ($invoice && $invoice->status !== 'Paid' && (float) $invoice->total > 0) {
                return ['ok' => false, 'error' => "Cannot provision: Invoice #{$invoice->id} is '{$invoice->status}', not Paid."];
            }
        }
    }

    $res = neko_local_api('ModuleCreate', ['serviceid' => $service_id]);
    return ($res['result'] ?? '') === 'success'
        ? ['ok' => true, 'message' => "Service #{$service_id} provisioned via ModuleCreate."]
        : ['ok' => false, 'error' => $res['message'] ?? 'ModuleCreate failed'];
}

function neko_tool_accept_paid_order($client_id, $order_id)
{
    $order = Capsule::table('tblorders')
        ->where('id', $order_id)
        ->where('userid', $client_id)
        ->first();

    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found for this client.'];
    }
    if ($order->status !== 'Pending') {
        return ['ok' => false, 'error' => "Order #{$order_id} status is '{$order->status}', not Pending."];
    }

    if (!empty($order->invoiceid)) {
        $invoice = Capsule::table('tblinvoices')->where('id', $order->invoiceid)->first();
        if ($invoice && $invoice->status !== 'Paid' && (float) $invoice->total > 0) {
            return ['ok' => false, 'error' => "Cannot accept order: Invoice #{$invoice->id} is '{$invoice->status}'."];
        }
    }

    $res = neko_local_api('AcceptOrder', [
        'orderid' => $order_id,
        'autosetup' => true,
        'sendemail' => true,
    ]);
    return ($res['result'] ?? '') === 'success'
        ? ['ok' => true, 'message' => "Order #{$order_id} accepted and provisioned."]
        : ['ok' => false, 'error' => $res['message'] ?? 'AcceptOrder failed'];
}

function neko_tool_run_vps_power_action($client_id, $service_id, $action)
{
    $action = strtolower(trim($action));
    if (!in_array($action, ['reboot', 'boot', 'shutdown'], true)) {
        return ['ok' => false, 'error' => 'Invalid power action. Must be reboot, boot, or shutdown.'];
    }

    $service = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
        ->where('tblhosting.id', $service_id)
        ->where('tblhosting.userid', $client_id)
        ->select('tblhosting.id', 'tblhosting.domainstatus', 'tblproducts.type')
        ->first();

    if (!$service) {
        return ['ok' => false, 'error' => 'Service not found for this client.'];
    }
    if ($service->domainstatus !== 'Active') {
        return ['ok' => false, 'error' => "Cannot run {$action}: service status is '{$service->domainstatus}'."];
    }
    if (!in_array($service->type, ['server', 'vps'], true)) {
        return ['ok' => false, 'error' => "Power actions only apply to server/VPS products (found '{$service->type}')."];
    }

    $res = neko_local_api('ModuleCustom', [
        'serviceid' => $service_id,
        'func_name' => $action,
    ]);

    return ($res['result'] ?? '') === 'success'
        ? ['ok' => true, 'message' => "Executed '{$action}' on VPS service #{$service_id}."]
        : ['ok' => false, 'error' => $res['message'] ?? "ModuleCustom({$action}) failed"];
}

function neko_tool_escalate_to_human($ticket_id, $client_id, $reason, $diagnostic_summary)
{
    if (function_exists('escalate_to_admin')) {
        escalate_to_admin($ticket_id, $client_id, $reason);
    }

    if ($diagnostic_summary !== '') {
        neko_local_api('AddTicketNote', [
            'ticketid' => $ticket_id,
            'message' => "[Neko AI Agent Diagnostic Note]\nReason: {$reason}\n\nFindings:\n{$diagnostic_summary}",
        ]);
    }

    return [
        'ok' => true,
        'escalated' => true,
        'reason' => $reason,
    ];
}

/**
 * Client-safe self-service diagnostic snapshot (for Client Area submitticket & viewticket).
 * Never exposes private admin notes, server credentials, or other clients' data.
 */
function neko_client_safe_diagnostics($client_id, $related_service = '', $domain_hint = '', $query_text = '')
{
    $client_id = (int) $client_id;
    $findings = [];
    $target_domain = neko_sanitize_domain((string) $domain_hint);
    $services = [];
    $unpaid_invoices = [];
    $domains = [];

    if ($client_id > 0) {
        $svc_res = neko_tool_get_client_services($client_id, 'all');
        $services = $svc_res['services'] ?? [];

        $inv_res = neko_tool_get_client_invoices($client_id, 'Unpaid');
        $unpaid_invoices = $inv_res['invoices'] ?? [];

        $dom_res = neko_tool_get_client_domains($client_id);
        $domains = $dom_res['domains'] ?? [];

        // Parse WHMCS relatedservice selector ("S123" or "D45")
        $rel = trim((string) $related_service);
        if (preg_match('/^S(\d+)$/i', $rel, $m)) {
            $sid = (int) $m[1];
            foreach ($services as $s) {
                if ((int) $s['service_id'] === $sid) {
                    if ($target_domain === '' && !empty($s['domain'])) {
                        $target_domain = neko_sanitize_domain($s['domain']);
                    }
                    $findings[] = [
                        'type' => 'service',
                        'status' => $s['status'],
                        'label' => "{$s['product_name']}" . (!empty($s['domain']) ? " ({$s['domain']})" : ''),
                        'detail' => $s['status'] === 'Suspended'
                            ? ('Suspended' . (!empty($s['suspend_reason']) ? ": {$s['suspend_reason']}" : ''))
                            : "Service status: {$s['status']} | Next Due: {$s['next_due_date']}",
                    ];
                }
            }
        } elseif (preg_match('/^D(\d+)$/i', $rel, $m)) {
            $did = (int) $m[1];
            foreach ($domains as $d) {
                if ((int) $d->domain_id === $did) {
                    if ($target_domain === '' && !empty($d->domain)) {
                        $target_domain = neko_sanitize_domain($d->domain);
                    }
                    $findings[] = [
                        'type' => 'domain',
                        'status' => $d->status,
                        'label' => $d->domain,
                        'detail' => "Domain status: {$d->status} | Expiry: {$d->expirydate}",
                    ];
                }
            }
        }

        // Flag any suspended/pending services or unpaid invoices automatically
        foreach ($services as $s) {
            if ($s['status'] === 'Suspended') {
                $findings[] = [
                    'type' => 'alert',
                    'status' => 'Suspended',
                    'label' => "Service #{$s['service_id']} ({$s['domain']})",
                    'detail' => 'Service is Suspended' . (!empty($s['suspend_reason']) ? " ({$s['suspend_reason']})" : ''),
                ];
            }
            if ($target_domain === '' && $s['status'] === 'Active' && !empty($s['domain'])) {
                $target_domain = neko_sanitize_domain($s['domain']);
            }
        }

        if (!empty($unpaid_invoices)) {
            foreach (array_slice($unpaid_invoices, 0, 3) as $inv) {
                $findings[] = [
                    'type' => 'invoice',
                    'status' => $inv['is_overdue'] ? 'Overdue' : 'Unpaid',
                    'label' => "Invoice #{$inv['invoice_num']}",
                    'detail' => "Amount: {$inv['total']} | Due: {$inv['due_date']}" . ($inv['is_overdue'] ? ' (OVERDUE)' : ''),
                    'invoice_id' => $inv['invoice_id'],
                ];
            }
        }
    }

    // Extract domain from query text if none selected yet
    if ($target_domain === '' && preg_match('/\b([a-z0-9-]+\.(?:com|net|org|io|co|bd|uk|xyz|info|biz|dev|app|online|store|shop|tech)(?:\.[a-z]{2})?)\b/i', (string) $query_text, $dm)) {
        $target_domain = neko_sanitize_domain($dm[1]);
    }

    // Run live DNS + HTTP/SSL check ONLY if client is logged in or verified ticket
    $dns_check = null;
    $http_ssl_check = null;
    if ($target_domain !== '' && $client_id > 0) {
        $dns_check = neko_tool_check_domain_dns($target_domain, $client_id);
        $http_ssl_check = neko_tool_check_website_http_ssl($target_domain);
    }

    // Public KB search (safe for everyone)
    $kb_matches = [];
    if (trim((string) $query_text) !== '') {
        $kb_res = neko_tool_search_knowledgebase($query_text);
        $kb_matches = array_slice($kb_res['kb_articles'] ?? [], 0, 3);
    }

    return [
        'ok' => true,
        'target_domain' => $target_domain,
        'findings' => $findings,
        'services_count' => count($services),
        'unpaid_invoices_count' => count($unpaid_invoices),
        'dns' => $dns_check,
        'http_ssl' => $http_ssl_check,
        'kb_articles' => $kb_matches,
    ];
}

/**
 * Fast 0-token account & ticket copilot snapshot for the Admin Ticket View header strip.
 */
function neko_ticket_copilot_snapshot($ticket_id)
{
    $ticket_id = (int) $ticket_id;
    $ticket = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
    if (!$ticket) {
        return ['ok' => false, 'error' => 'Ticket not found'];
    }

    $client_id = (int) ($ticket->userid ?? 0);
    $analysis = Capsule::table('mod_gemini_ticket_analysis')->where('ticket_id', $ticket_id)->first();

    $overview = neko_tool_get_client_overview($client_id);
    $services = ($client_id > 0) ? (neko_tool_get_client_services($client_id, 'all')['services'] ?? []) : [];
    $unpaid = ($client_id > 0) ? (neko_tool_get_client_invoices($client_id, 'Unpaid')['invoices'] ?? []) : [];
    $domains_list = ($client_id > 0) ? (neko_tool_get_client_domains($client_id)['domains'] ?? []) : [];

    $domain_chips = [];
    foreach ($services as $s) {
        if (!empty($s['domain'])) {
            $domain_chips[] = $s['domain'];
        }
    }
    foreach ($domains_list as $d) {
        if (!empty($d->domain)) {
            $domain_chips[] = $d->domain;
        }
    }
    $domain_chips = array_slice(array_values(array_unique($domain_chips)), 0, 5);

    $unpaid_total = 0.0;
    foreach ($unpaid as $u) {
        $unpaid_total += (float) $u['total'];
    }

    $agent_action_count = 0;
    try {
        if (Capsule::schema()->hasTable('mod_gemini_agent_actions')) {
            $agent_action_count = Capsule::table('mod_gemini_agent_actions')->where('ticket_id', $ticket_id)->count();
        }
    } catch (\Exception $e) {}

    return [
        'ok' => true,
        'ticket_id' => $ticket_id,
        'client_id' => $client_id,
        'mood' => $analysis ? $analysis->mood : 'neutral',
        'needs_human' => $analysis ? !empty($analysis->needs_human) : false,
        'escalation_level' => $analysis ? (float) $analysis->escalation_level : 0.3,
        'credit_balance' => $overview['credit_balance'] ?? 0.0,
        'currency_prefix' => $overview['currency_prefix'] ?? '$',
        'total_spent' => $overview['total_spent'] ?? 0.0,
        'services' => array_map(function ($s) {
            return [
                'id' => $s['service_id'],
                'name' => $s['product_name'],
                'domain' => $s['domain'],
                'status' => $s['status'],
                'suspend_reason' => $s['suspend_reason'],
            ];
        }, array_slice($services, 0, 6)),
        'unpaid_count' => count($unpaid),
        'unpaid_total' => round($unpaid_total, 2),
        'domain_chips' => $domain_chips,
        'agent_action_count' => $agent_action_count,
    ];
}
