<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/includes/agent_tools.php';
require_once __DIR__ . '/includes/agent_runner.php';

/**
 * Shared entrypoint for TicketOpen and TicketUserReply autopilot handling.
 */
function neko_handle_incoming_ticket_event($ticket_id, $latest_message, $is_new_ticket = false)
{
    $config = get_gemini_config();
    if (empty($config['auto_reply_enabled'])) {
        return;
    }

    $ticket_data = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
    if (!$ticket_data || strcasecmp((string) $ticket_data->status, 'Closed') === 0) {
        return;
    }

    $message = trim((string) $latest_message);
    if ($message === '') {
        $message = (string) ($ticket_data->message ?? '');
    }

    // Check Autopilot Departments first (blank = all departments)
    $autopilot_departments = array_filter(array_map('trim', explode(',', (string) ($config['autopilot_departments'] ?? ''))));
    if (!empty($autopilot_departments) && !in_array((string) $ticket_data->did, $autopilot_departments, true)) {
        logActivity("Neko AI: Skipping auto-reply for ticket #$ticket_id - department #{$ticket_data->did} not in autopilot list");
        return;
    }

    // 1. Check if user explicitly asked for a human agent (bypass cooldown)
    if (should_skip_ai_reply($message)) {
        mark_ticket_for_human($ticket_id);
        logActivity("Neko AI: Escalated ticket #$ticket_id - customer requested human agent");
        return;
    }

    // 2. If ticket was already escalated to human, do NOT let AI jump back in unless an admin cleared needs_human
    $existing_analysis = Capsule::table('mod_gemini_ticket_analysis')->where('ticket_id', $ticket_id)->first();
    if ($existing_analysis && !empty($existing_analysis->needs_human)) {
        logActivity("Neko AI: Skipping auto-reply for ticket #$ticket_id - already flagged for human support");
        return;
    }

    // 3. Cooldown check: avoid double-replying within 2 minutes
    $recent_reply = Capsule::table('mod_gemini_ai_logs')
        ->where('ticket_id', $ticket_id)
        ->whereIn('response_type', ['generate', 'agent_solve'])
        ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-2 minutes')))
        ->first();

    if ($recent_reply) {
        logActivity("Neko AI: Skipping auto-reply for ticket #$ticket_id - cooldown active");
        return;
    }

    // 4. Analyze ticket mood & escalation heuristics BEFORE calling AI
    $analysis = analyze_ticket_content($message, $ticket_id);
    if ($analysis['escalation_level'] > 0.8) {
        mark_ticket_for_human($ticket_id);
        logActivity("Neko AI: Ticket #$ticket_id escalated due to high mood escalation ({$analysis['mood']})");
        return;
    }

    // 5. Autopilot Delay Queue
    $delay = (int) ($config['autopilot_delay'] ?? 0);
    if ($delay > 0) {
        Capsule::table('mod_gemini_reply_queue')->where('ticket_id', $ticket_id)->where('status', 'pending')->delete();
        Capsule::table('mod_gemini_reply_queue')->insert([
            'ticket_id' => $ticket_id,
            'user_message' => $message,
            'status' => 'pending',
            'scheduled_at' => date('Y-m-d H:i:s', strtotime("+{$delay} minutes")),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        logActivity("Neko AI: Queued autopilot reply for ticket #$ticket_id (Delay: {$delay} mins)");
        return;
    }

    // 6. Execute autonomous agent or legacy reply
    neko_execute_autopilot_solve($ticket_id, $message, $analysis, $config);
}

/**
 * Run the agent (or legacy generator) and enforce uncertain-behavior safety policies.
 */
function neko_execute_autopilot_solve($ticket_id, $message, array $analysis, array $config)
{
    $ticket = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
    if (!$ticket) {
        return false;
    }

    $agent_enabled = !empty($config['agent_enabled']);
    if ($agent_enabled) {
        $result = neko_run_whmcs_agent($ticket_id, $message, $config, ['mode' => 'autopilot']);
        $response_type = 'agent_solve';
    } else {
        if ($analysis['confidence'] < $config['confidence_threshold']) {
            logActivity("Neko AI: Low pre-check confidence ({$analysis['confidence']}) for ticket #$ticket_id - skipping auto-reply");
            return false;
        }
        $result = generate_ai_ticket_response($message, $ticket_id, $analysis);
        $response_type = 'generate';
    }

    if (!$result || empty($result['success'])) {
        logActivity("Neko AI: Failed to generate response for ticket #$ticket_id - " . ($result['error'] ?? 'Unknown error'));
        return false;
    }

    $ai_confidence = (float) ($result['confidence'] ?? $analysis['confidence'] ?? 0.5);
    $ai_mood = $result['mood'] ?? $analysis['mood'] ?? 'neutral';
    $ai_escalation = (float) ($result['escalation_level'] ?? $analysis['escalation_level'] ?? 0.3);
    $needs_human = !empty($result['needs_human']) || ($result['can_resolve'] ?? true) === false || $ai_escalation > 0.8;

    // Persist updated analysis from the agent
    Capsule::table('mod_gemini_ticket_analysis')->updateOrInsert(
        ['ticket_id' => $ticket_id],
        [
            'mood' => $ai_mood,
            'escalation_level' => $ai_escalation,
            'key_issues' => extract_key_issues($message),
            'needs_human' => $needs_human ? 1 : 0,
            'analyzed_at' => date('Y-m-d H:i:s'),
        ]
    );

    // Log interaction
    Capsule::table('mod_gemini_ai_logs')->insert([
        'ticket_id' => $ticket_id,
        'user_message' => substr($message, 0, 2000),
        'ai_response' => $result['response'] ?? '',
        'confidence_score' => $ai_confidence,
        'mood_detected' => $ai_mood,
        'response_type' => $response_type,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $threshold = (float) ($config['confidence_threshold'] ?? 0.7);
    $uncertain_policy = $config['agent_uncertain_behavior'] ?? 'draft_note';

    // CRITICAL: Never send an uncertain or human-needed reply to an annoyed/waiting customer
    if ($needs_human || $ai_confidence < $threshold) {
        $reason = !empty($result['escalation_reason'])
            ? $result['escalation_reason']
            : ($needs_human ? 'Agent determined human intervention is required' : "Confidence ({$ai_confidence}) below threshold ({$threshold})");

        $trace_lines = [];
        foreach (($result['tools_trace'] ?? []) as $tr) {
            $trace_lines[] = "- {$tr['tool']}: {$tr['summary']}";
        }
        $trace_block = !empty($trace_lines) ? "\nTools Executed:\n" . implode("\n", $trace_lines) : '';
        $internal_note = !empty($result['internal_note']) ? "\nAgent Findings:\n{$result['internal_note']}" : '';
        $draft_block = !empty($result['response']) ? "\nSuggested Draft Reply (NOT sent to client):\n{$result['response']}" : '';

        neko_local_api('AddTicketNote', [
            'ticketid' => $ticket_id,
            'message' => "[Neko AI Agent - Human Review Required]\nReason: {$reason}\nMood: {$ai_mood} | Confidence: " . round($ai_confidence * 100) . "%{$internal_note}{$trace_block}{$draft_block}",
        ]);

        if ($uncertain_policy === 'escalate_notify') {
            if (empty($result['escalated_via_tool'])) {
                escalate_to_admin($ticket_id, (int) ($ticket->userid ?? 0), $reason);
            }
            notify_client_escalation($ticket_id, $config);
        } elseif ($uncertain_policy === 'escalate_silent') {
            if (empty($result['escalated_via_tool'])) {
                escalate_to_admin($ticket_id, (int) ($ticket->userid ?? 0), $reason);
            }
        }
        // 'draft_note': saved private admin note without bothering customer with a robotic reply

        logActivity("Neko AI: Held public reply for ticket #$ticket_id (policy={$uncertain_policy}, conf={$ai_confidence}, reason={$reason})");
        return false;
    }

    // Verified & confident -> send reply to customer and log internal findings note if tools ran
    $analysis['mood'] = $ai_mood;
    $analysis['confidence'] = $ai_confidence;
    add_ticket_reply($ticket_id, $result, $analysis);

    if (!empty($result['internal_note'])) {
        neko_local_api('AddTicketNote', [
            'ticketid' => $ticket_id,
            'message' => "[Neko AI Agent Resolution Note]\n{$result['internal_note']}",
        ]);
    }

    logActivity("Neko AI: Auto-solved & replied to ticket #$ticket_id (confidence: {$ai_confidence})");
    return true;
}

// Hook for ticket creation
add_hook('TicketOpen', 1, function ($vars) {
    $ticket_id = (int) ($vars['ticketid'] ?? 0);
    if ($ticket_id <= 0) {
        return;
    }

    $message = Capsule::table('tblticketreplies')
        ->where('tid', $ticket_id)
        ->orderBy('id', 'desc')
        ->value('message');

    if (empty($message)) {
        $message = Capsule::table('tbltickets')->where('id', $ticket_id)->value('message');
    }

    neko_handle_incoming_ticket_event($ticket_id, $message, true);
});

// Hook for ticket replies from users
add_hook('TicketUserReply', 1, function ($vars) {
    $ticket_id = (int) ($vars['ticketid'] ?? 0);
    if ($ticket_id <= 0) {
        return;
    }

    $message = $vars['message'] ?? '';
    neko_handle_incoming_ticket_event($ticket_id, $message, false);
});

// Hook to add AI reply & Copilot AJAX handlers in admin area
add_hook('AdminAreaHeaderOutput', 1, function ($vars) {
    if ($vars['filename'] == 'supporttickets' && isset($_POST['action'])) {
        $action = $_POST['action'];
        $ticket_id = (int) ($_POST['ticket_id'] ?? 0);

        if ($action == 'generate_ai_reply') {
            $mode = $_POST['reply_mode'] ?? 'generate';
            $lang = $_POST['reply_lang'] ?? 'auto';
            $current_message = $_POST['current_message'] ?? '';
            $custom_prompt = $_POST['custom_prompt'] ?? '';
            $config = get_gemini_config();

            // Fetch latest customer message (or fallback to initial ticket message)
            $latest_message = Capsule::table('tblticketreplies')
                ->where('tid', $ticket_id)
                ->where('admin', '')
                ->orderBy('id', 'desc')
                ->value('message');
            if (empty($latest_message)) {
                $latest_message = Capsule::table('tbltickets')->where('id', $ticket_id)->value('message');
            }

            $agent_modes = ['generate', 'agent_solve', 'triage'];
            if (!empty($config['agent_enabled']) && in_array($mode, $agent_modes, true)) {
                $runner_mode = ($mode === 'agent_solve') ? 'admin_solve' : (($mode === 'triage') ? 'triage' : 'admin_assist');
                $ai_response = neko_run_whmcs_agent($ticket_id, (string) $latest_message, $config, [
                    'mode' => $runner_mode,
                    'lang' => $lang,
                    'custom_prompt' => $custom_prompt,
                ]);
                if (!empty($ai_response['success'])) {
                    $log_id = Capsule::table('mod_gemini_ai_logs')->insertGetId([
                        'ticket_id' => $ticket_id,
                        'user_message' => substr((string) $latest_message, 0, 2000),
                        'ai_response' => $ai_response['response'] ?? '',
                        'confidence_score' => (float) ($ai_response['confidence'] ?? 0.5),
                        'mood_detected' => $ai_response['mood'] ?? 'neutral',
                        'response_type' => $mode,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $ai_response['log_id'] = (int) $log_id;
                }
            } else {
                // For thread-level modes (summarize, triage, generate_kb, internal_handover), pass the full thread
                $prompt_input = (string) $latest_message;
                if (in_array($mode, ['summarize', 'triage', 'generate_kb', 'internal_handover'], true)) {
                    $thread = neko_tool_get_ticket_thread($ticket_id, $config);
                    if (!empty($thread['thread'])) {
                        $lines = [];
                        foreach ($thread['thread'] as $tmsg) {
                            $lines[] = "[{$tmsg['date']} | {$tmsg['role']} | {$tmsg['author']}]:\n{$tmsg['message']}";
                        }
                        $prompt_input = implode("\n\n", $lines);
                    }
                }
                $analysis = analyze_ticket_content((string) $latest_message, $ticket_id);
                $ai_response = generate_ai_ticket_response($prompt_input, $ticket_id, $analysis, $mode, $current_message, $lang);
            }

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode($ai_response);
            exit;
        }

        if ($action == 'analyze_ticket_mood') {
            $latest_message = Capsule::table('tblticketreplies')
                ->where('tid', $ticket_id)
                ->where('admin', '')
                ->orderBy('id', 'desc')
                ->value('message');
            if (empty($latest_message)) {
                $latest_message = Capsule::table('tbltickets')->where('id', $ticket_id)->value('message');
            }
            $analysis = analyze_ticket_content((string) $latest_message, $ticket_id);
            $snapshot = neko_ticket_copilot_snapshot($ticket_id);

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(array_merge(['success' => true], $analysis, ['snapshot' => $snapshot]));
            exit;
        }

        if ($action == 'neko_quick_dns_ssl_check') {
            $domain = trim((string) ($_POST['domain'] ?? ''));
            $ticket = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
            $client_id = (int) ($ticket->userid ?? 0);

            $dns = neko_tool_check_domain_dns($domain, $client_id);
            $http_ssl = neko_tool_check_website_http_ssl($domain);

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => !empty($dns['ok']),
                'domain' => $dns['domain'] ?? $domain,
                'dns' => $dns,
                'http_ssl' => $http_ssl,
                'error' => $dns['error'] ?? null,
            ]);
            exit;
        }

        if ($action == 'neko_save_admin_note') {
            $note_text = trim((string) ($_POST['note'] ?? ''));
            if ($ticket_id <= 0 || $note_text === '') {
                ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Empty note or invalid ticket']);
                exit;
            }
            $res = neko_local_api('AddTicketNote', [
                'ticketid' => $ticket_id,
                'message' => $note_text,
            ]);
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => ($res['result'] ?? '') === 'success',
                'error' => $res['message'] ?? null,
            ]);
            exit;
        }

        if ($action == 'neko_reset_ticket_escalation') {
            Capsule::table('mod_gemini_ticket_analysis')
                ->where('ticket_id', $ticket_id)
                ->update(['needs_human' => 0, 'escalation_level' => 0.3]);
            Capsule::table('mod_gemini_escalations')
                ->where('ticket_id', $ticket_id)
                ->where('resolved', false)
                ->update(['resolved' => true, 'resolved_at' => date('Y-m-d H:i:s')]);
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action == 'neko_submit_ai_feedback') {
            neko_ensure_agent_tables();
            $log_id = (int) ($_POST['log_id'] ?? 0);
            $rating = $_POST['rating'] === 'positive' ? 'positive' : 'negative';
            if ($log_id > 0) {
                Capsule::table('mod_gemini_ai_logs')->where('id', $log_id)->update([
                    'admin_feedback' => $rating,
                ]);
            }
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => $log_id > 0, 'rating' => $rating]);
            exit;
        }
    }
});

// Hook to inject modern AI Support Copilot UI in admin ticket view
add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    if ($vars['filename'] == 'supporttickets' && isset($_GET['id'])) {
        $ticket_id = (int) $_GET['id'];

        $html = <<<'HTML'
<script>
$(document).ready(function() {
    if ($('#nekoAdminCopilot').length > 0) return;

    var target = $('#replymessage');
    if (target.length === 0) target = $('#content');
    if (target.length === 0) target = $('textarea[name="replymessage"]');
    if (target.length === 0) return;

    var insertAnchor = target.closest('.md-editor');
    if (insertAnchor.length === 0) insertAnchor = target;

    var copilotHtml = `
        <div id="nekoAdminCopilot" class="neko-copilot-card">
            <div class="neko-copilot-header">
                <div class="neko-copilot-brand">
                    <span class="neko-copilot-icon"><i class="fas fa-robot"></i></span>
                    <div>
                        <strong>Neko AI Support Copilot</strong>
                        <span class="neko-copilot-sub">Autonomous WHMCS Diagnostics &amp; Reply Assistant</span>
                    </div>
                </div>
                <div class="neko-copilot-badges">
                    <span id="currentMood" class="label label-default">Mood: Analyzing...</span>
                    <span id="confidenceBadge" class="label label-default">Confidence: ...</span>
                    <span id="nekoUnpaidBadge" class="label label-default" style="display:none;"></span>
                    <span id="nekoCreditBadge" class="label label-info" style="display:none;"></span>
                    <span id="needsHumanBadge" class="label label-danger" style="display:none;">
                        <i class="fas fa-exclamation-triangle"></i> Human Required
                        <a href="javascript:void(0)" onclick="nekoResetEscalation()" title="Clear escalation & resume AI" style="color:#fff; margin-left:4px; text-decoration:underline;">Reset</a>
                    </span>
                </div>
            </div>

            <div id="nekoSnapshotStrip" class="neko-snapshot-strip" style="display:none;"></div>

            <div class="neko-copilot-toolbar">
                <button type="button" class="btn btn-sm neko-btn-primary" onclick="generateAIResponse('generate')">
                    <i class="fas fa-stethoscope"></i> Diagnose &amp; Draft
                </button>
                <button type="button" class="btn btn-sm neko-btn-solve" onclick="if(confirm('Run Neko AI Agent with enabled WHMCS actions (unsuspend/email/domain) and draft the reply?')) generateAIResponse('agent_solve')">
                    <i class="fas fa-bolt"></i> Auto-Solve &amp; Draft
                </button>
                <button type="button" class="btn btn-sm btn-default neko-btn-ghost" onclick="generateAIResponse('summarize')">
                    <i class="fas fa-list-ul"></i> Summarize
                </button>
                <button type="button" class="btn btn-sm btn-default neko-btn-ghost" onclick="generateAIResponse('triage')">
                    <i class="fas fa-search-plus"></i> Root-Cause Triage
                </button>
                <button type="button" class="btn btn-sm btn-default neko-btn-ghost" onclick="generateAIResponse('internal_handover')">
                    <i class="fas fa-clipboard-check"></i> Handover Note
                </button>
                <button type="button" class="btn btn-sm btn-default neko-btn-ghost" onclick="generateAIResponse('generate_kb')">
                    <i class="fas fa-book"></i> Draft KB
                </button>

                <div class="btn-group dropdown">
                    <button type="button" id="aiReplyBtn" class="btn btn-sm btn-default dropdown-toggle neko-btn-ghost" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-pen-nib"></i> <span class="btn-text">Rewrite / Tone</span> <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li class="dropdown-header">Tone</li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_empathetic')">Empathetic</a></li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_professional')">Professional</a></li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_technical')">Technical</a></li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_friendly')">Friendly</a></li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_formal')">Formal</a></li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_firm')">Firm / Direct</a></li>
                        <li class="divider dropdown-divider"></li>
                        <li class="dropdown-header">Enhance</li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_fix_grammar')">Fix Spelling &amp; Grammar</a></li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_shorter')">Make Shorter</a></li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_longer')">Expand Detail</a></li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite')">Standard Polish</a></li>
                        <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_translate')">Translate Draft...</a></li>
                    </ul>
                </div>

                <select id="nekoAdminLang" class="form-control input-sm neko-lang-select" title="Reply language">
                    <option value="auto">Lang: Auto</option>
                    <option value="english">English</option>
                    <option value="bangla">Bangla (বাংলা)</option>
                </select>

                <input type="text" id="aiCustomPrompt" class="form-control input-sm neko-custom-input" placeholder="Optional instruction (e.g. offer 10% credit, check MX)..." />
            </div>

            <div id="nekoCopilotStatus" class="neko-copilot-status" style="display:none;"></div>
            <div id="nekoAgentTrace" class="neko-agent-trace" style="display:none;"></div>

            <div id="nekoStaffPanel" class="neko-staff-panel" style="display:none;">
                <div class="neko-staff-panel-head">
                    <strong id="nekoStaffPanelTitle"><i class="fas fa-clipboard-list"></i> Copilot Output</strong>
                    <div>
                        <button type="button" class="btn btn-xs btn-default" onclick="nekoCopyStaffPanel()"><i class="fas fa-copy"></i> Copy</button>
                        <button type="button" class="btn btn-xs btn-primary" onclick="nekoSaveStaffPanelAsNote()"><i class="fas fa-sticky-note"></i> Save Private Note</button>
                        <button type="button" class="btn btn-xs btn-info" onclick="nekoInsertStaffPanelToReply()"><i class="fas fa-level-down-alt"></i> Put in Reply Box</button>
                        <button type="button" class="btn btn-xs btn-link" onclick="$('#nekoStaffPanel').slideUp(150)"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <pre id="nekoStaffPanelBody" class="neko-staff-panel-body"></pre>
            </div>
        </div>
    `;

    insertAnchor.before(copilotHtml);
    analyzeCurrentTicketMood(__TICKET_ID__);
});

function nekoEscapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function(m) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
}

function nekoGetEditorHandles() {
    var replyArea = $('#replymessage');
    if (replyArea.length === 0) replyArea = $('#content');
    if (replyArea.length === 0) replyArea = $('textarea[name="replymessage"]');
    var mde = replyArea.data('markdown');
    return { area: replyArea, mde: mde };
}

function nekoSetReplyText(text) {
    var h = nekoGetEditorHandles();
    if (h.mde) {
        h.mde.setContent(text);
    } else {
        h.area.val(text);
    }
}

function nekoGetReplyText() {
    var h = nekoGetEditorHandles();
    return h.mde ? h.mde.getContent() : h.area.val();
}

function generateAIResponse(mode, lang) {
    var ticketId = __TICKET_ID__;
    var chosenLang = lang || $('#nekoAdminLang').val() || 'auto';
    var currentMessage = nekoGetReplyText();
    var staffModes = ['summarize', 'triage', 'generate_kb', 'internal_handover'];
    var isStaffOnly = staffModes.indexOf(mode) !== -1;

    var customPrompt = $('#aiCustomPrompt').val() || '';
    if (mode === 'rewrite_translate') {
        customPrompt = prompt("Translate draft into which language?", "Bengali");
        if (!customPrompt) return;
        customPrompt = "Translate this to " + customPrompt;
    }

    var originalContent = currentMessage;
    var statusEl = $('#nekoCopilotStatus');
    $('#nekoAdminCopilot button').prop('disabled', true);
    statusEl.html('<i class="fas fa-spinner fa-spin"></i> Neko AI is analyzing ticket context &amp; running diagnostics...').show();
    $('#nekoAgentTrace').hide().empty();

    if (!isStaffOnly) {
        nekoSetReplyText('Neko AI Agent is checking WHMCS data & drafting reply... Please wait.');
    }

    $.post('supporttickets.php', {
        action: 'generate_ai_reply',
        ticket_id: ticketId,
        reply_mode: mode,
        reply_lang: chosenLang,
        current_message: currentMessage,
        custom_prompt: customPrompt
    }, function(response) {
        $('#nekoAdminCopilot button').prop('disabled', false);
        statusEl.hide();

        if (response && response.success) {
            if (isStaffOnly) {
                var titles = {
                    summarize: 'Ticket Thread Summary',
                    triage: 'Root-Cause Triage Checklist',
                    generate_kb: 'Draft Knowledge Base Article',
                    internal_handover: 'Internal Staff Handover Note'
                };
                $('#nekoStaffPanelTitle').html('<i class="fas fa-clipboard-list"></i> ' + (titles[mode] || 'Copilot Output'));
                $('#nekoStaffPanelBody').text(response.response || '');
                $('#nekoStaffPanel').slideDown(150);
            } else {
                nekoSetReplyText(response.response);
            }

            if (response.mood && response.confidence !== undefined) {
                updateMoodDisplay(response.mood, response.confidence, response.escalation_level || 0);
            }

            if ((response.tools_trace && response.tools_trace.length > 0) || response.internal_note || response.needs_human || response.log_id) {
                var html = '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">';
                html += '<div><strong><i class="fas fa-microscope"></i> Neko Agent Diagnostics:</strong>';
                if (response.model_used) {
                    html += ' <span class="label label-default">' + nekoEscapeHtml(response.model_used) + (response.tokens_used ? ' · ' + response.tokens_used + ' tok' : '') + '</span>';
                }
                if (response.needs_human) {
                    html += ' <span class="label label-danger">Human Intervention Recommended</span>';
                    if (response.escalation_reason) {
                        html += ' <span style="color:#c0392b;">(' + nekoEscapeHtml(response.escalation_reason) + ')</span>';
                    }
                }
                html += '</div>';
                if (response.log_id) {
                    html += '<div id="nekoFeedbackWrap"><small style="color:#64748b; margin-right:4px;">Rate draft:</small>' +
                        '<button type="button" class="btn btn-xs btn-default" onclick="nekoRateDraft(' + response.log_id + ', \'positive\')"><i class="fas fa-thumbs-up text-success"></i> Helpful</button> ' +
                        '<button type="button" class="btn btn-xs btn-default" onclick="nekoRateDraft(' + response.log_id + ', \'negative\')"><i class="fas fa-thumbs-down text-danger"></i> Needs Work</button></div>';
                }
                html += '</div>';
                if (response.internal_note) {
                    html += '<div style="margin-top:5px; color:#1f2937;">' + nekoEscapeHtml(response.internal_note) + '</div>';
                }
                if (response.tools_trace && response.tools_trace.length > 0) {
                    html += '<ul style="margin:6px 0 0 18px; padding:0;">';
                    response.tools_trace.forEach(function(tr) {
                        var icon = tr.ok ? '<span style="color:#16a34a;">&#10004;</span>' : '<span style="color:#dc2626;">&#10008;</span>';
                        html += '<li>' + icon + ' <code>' + nekoEscapeHtml(tr.tool) + '</code> &rarr; ' + nekoEscapeHtml(tr.summary) + '</li>';
                    });
                    html += '</ul>';
                }
                $('#nekoAgentTrace').html(html).slideDown(150);
            }
        } else {
            if (!isStaffOnly) nekoSetReplyText(originalContent);
            alert('AI Error: ' + (response ? response.error : 'Unknown error'));
        }
    }, 'json').fail(function() {
        $('#nekoAdminCopilot button').prop('disabled', false);
        statusEl.hide();
        if (!isStaffOnly) nekoSetReplyText(originalContent);
        alert('Could not communicate with the AI module. Please try again.');
    });
}

function nekoRateDraft(logId, rating) {
    $.post('supporttickets.php', {
        action: 'neko_submit_ai_feedback',
        ticket_id: __TICKET_ID__,
        log_id: logId,
        rating: rating
    }, function(res) {
        if (res && res.success) {
            var label = rating === 'positive'
                ? '<span class="label label-success"><i class="fas fa-check"></i> Rated Helpful</span>'
                : '<span class="label label-warning"><i class="fas fa-flag"></i> Flagged for Review</span>';
            $('#nekoFeedbackWrap').html(label);
        }
    }, 'json');
}

function nekoRunQuickDomainDiag(domain) {
    var statusEl = $('#nekoCopilotStatus');
    statusEl.html('<i class="fas fa-spinner fa-spin"></i> Running live DNS, HTTP &amp; SSL check for <strong>' + nekoEscapeHtml(domain) + '</strong>...').show();
    $.post('supporttickets.php', {
        action: 'neko_quick_dns_ssl_check',
        ticket_id: __TICKET_ID__,
        domain: domain
    }, function(res) {
        statusEl.hide();
        if (!res || !res.success) {
            alert('Diagnostic error: ' + (res ? res.error : 'Failed'));
            return;
        }
        var d = res.dns || {};
        var recs = d.dns_records || {};
        var cmp = d.whmcs_comparison || null;
        var h = res.http_ssl || {};
        var ssl = h.ssl_certificate || {};
        var httpsCode = (h.http_checks && h.http_checks.https) ? h.http_checks.https.http_code : 0;
        var httpCode = (h.http_checks && h.http_checks.http) ? h.http_checks.http.http_code : 0;

        var lines = [
            'LIVE DIAGNOSTIC REPORT: ' + res.domain,
            '--------------------------------------------------',
            'A Records   : ' + ((recs.A || []).join(', ') || 'None'),
            'NS Records  : ' + ((recs.NS || []).join(', ') || 'None'),
            'MX Records  : ' + ((recs.MX || []).join(', ') || 'None'),
            'TXT Records : ' + ((recs.TXT || []).slice(0, 3).join(' | ') || 'None')
        ];
        if (cmp) {
            lines.push('WHMCS Server IP : ' + (cmp.expected_server_ip || 'N/A') + ' (Matches A: ' + (cmp.a_record_matches_server ? 'YES' : 'NO') + ')');
        }
        lines.push('HTTP / HTTPS    : HTTP ' + httpCode + ' | HTTPS ' + httpsCode);
        if (ssl && ssl.present) {
            lines.push('SSL Certificate : CN=' + (ssl.subject_cn || '') + ' | Issuer=' + (ssl.issuer || '') + ' | Expires=' + (ssl.valid_to || '') + (ssl.is_expired ? ' [EXPIRED!]' : ' [VALID]'));
        } else {
            lines.push('SSL Certificate : Not reachable (' + (ssl.error || 'no cert') + ')');
        }

        $('#nekoStaffPanelTitle').html('<i class="fas fa-globe"></i> Live DNS &amp; SSL Check: ' + nekoEscapeHtml(res.domain));
        $('#nekoStaffPanelBody').text(lines.join("\n"));
        $('#nekoStaffPanel').slideDown(150);
    }, 'json');
}

function nekoCopyStaffPanel() {
    var txt = $('#nekoStaffPanelBody').text();
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt);
    } else {
        var ta = $('<textarea>').val(txt).appendTo('body').select();
        document.execCommand('copy');
        ta.remove();
    }
    $('#nekoCopilotStatus').html('<i class="fas fa-check text-success"></i> Copied to clipboard.').show().delay(1800).fadeOut();
}

function nekoInsertStaffPanelToReply() {
    var txt = $('#nekoStaffPanelBody').text();
    nekoSetReplyText(txt);
}

function nekoSaveStaffPanelAsNote() {
    var txt = $('#nekoStaffPanelBody').text();
    if (!txt) return;
    $.post('supporttickets.php', {
        action: 'neko_save_admin_note',
        ticket_id: __TICKET_ID__,
        note: txt
    }, function(res) {
        if (res && res.success) {
            $('#nekoCopilotStatus').html('<i class="fas fa-check text-success"></i> Saved as Private Admin Note on this ticket.').show().delay(2500).fadeOut();
        } else {
            alert('Failed to save note: ' + (res ? res.error : 'Unknown error'));
        }
    }, 'json');
}

function nekoResetEscalation() {
    $.post('supporttickets.php', {
        action: 'neko_reset_ticket_escalation',
        ticket_id: __TICKET_ID__
    }, function(res) {
        if (res && res.success) {
            $('#needsHumanBadge').hide();
            $('#nekoCopilotStatus').html('<i class="fas fa-check text-success"></i> AI escalation cleared — Autopilot can resume on future replies.').show().delay(2500).fadeOut();
        }
    }, 'json');
}

function analyzeCurrentTicketMood(ticketId) {
    $.post('supporttickets.php', {
        action: 'analyze_ticket_mood',
        ticket_id: ticketId
    }, function(response) {
        if (response && response.success) {
            updateMoodDisplay(response.mood, response.confidence, response.escalation_level);
            if (response.snapshot && response.snapshot.ok) {
                renderSnapshotStrip(response.snapshot);
            }
        }
    }, 'json');
}

function renderSnapshotStrip(snap) {
    if (snap.needs_human) {
        $('#needsHumanBadge').show();
    }
    if (snap.unpaid_count > 0) {
        $('#nekoUnpaidBadge').removeClass('label-default').addClass('label-danger')
            .text('Unpaid: ' + snap.unpaid_count + ' (' + snap.currency_prefix + snap.unpaid_total.toFixed(2) + ')').show();
    } else if (snap.client_id > 0) {
        $('#nekoUnpaidBadge').removeClass('label-danger').addClass('label-success').text('Invoices: Paid').show();
    }
    if (snap.credit_balance > 0) {
        $('#nekoCreditBadge').text('Credit: ' + snap.currency_prefix + snap.credit_balance.toFixed(2)).show();
    }

    var parts = [];
    if (snap.services && snap.services.length > 0) {
        snap.services.slice(0, 4).forEach(function(s) {
            var cls = s.status === 'Active' ? 'neko-chip-ok' : (s.status === 'Suspended' ? 'neko-chip-bad' : 'neko-chip-warn');
            var label = nekoEscapeHtml(s.name) + (s.domain ? ' (' + nekoEscapeHtml(s.domain) + ')' : '') + ' · ' + nekoEscapeHtml(s.status);
            parts.push('<span class="neko-chip ' + cls + '">' + label + '</span>');
        });
    }
    if (snap.domain_chips && snap.domain_chips.length > 0) {
        snap.domain_chips.forEach(function(dom) {
            parts.push('<a href="javascript:void(0)" class="neko-chip neko-chip-diag" onclick="nekoRunQuickDomainDiag(\'' + nekoEscapeHtml(dom) + '\')" title="Run instant live DNS & SSL check"><i class="fas fa-stethoscope"></i> Check ' + nekoEscapeHtml(dom) + '</a>');
        });
    }
    if (parts.length > 0) {
        $('#nekoSnapshotStrip').html(parts.join(' ')).show();
    }
}

function updateMoodDisplay(mood, confidence, escalation) {
    var moodLabel = $('#currentMood');
    var confidenceBadge = $('#confidenceBadge');

    moodLabel.text('Mood: ' + mood.charAt(0).toUpperCase() + mood.slice(1));
    moodLabel.removeClass('label-default label-success label-info label-warning label-danger');

    var moodStyles = {
        'angry': 'label-danger',
        'frustrated': 'label-warning',
        'urgent': 'label-danger',
        'satisfied': 'label-success',
        'calm': 'label-success',
        'neutral': 'label-info'
    };
    moodLabel.addClass(moodStyles[(mood || 'neutral').toLowerCase()] || 'label-info');

    var confidencePercent = Math.round((confidence || 0.5) * 100);
    confidenceBadge.text('Confidence: ' + confidencePercent + '%');
    confidenceBadge.removeClass('label-default label-danger label-warning label-success');

    if (confidencePercent >= 80) {
        confidenceBadge.addClass('label-success');
    } else if (confidencePercent >= 60) {
        confidenceBadge.addClass('label-warning');
    } else {
        confidenceBadge.addClass('label-danger');
    }

    if (escalation > 0.7) {
        $('#needsHumanBadge').show();
    }
}
</script>
<style>
.neko-copilot-card {
    margin: 12px 0;
    background: #ffffff;
    border: 1px solid #dbe4ee;
    border-radius: 10px;
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.06);
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.neko-copilot-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
    color: #fff;
}
.neko-copilot-brand {
    display: flex;
    align-items: center;
    gap: 10px;
}
.neko-copilot-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: rgba(56, 189, 248, 0.22);
    color: #38bdf8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
}
.neko-copilot-sub {
    display: block;
    font-size: 11px;
    color: #cbd5e1;
}
.neko-copilot-badges .label {
    font-size: 11px;
    padding: 4px 8px;
    margin-left: 4px;
    border-radius: 4px;
}
.neko-snapshot-strip {
    padding: 8px 14px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}
.neko-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid transparent;
}
.neko-chip-ok { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
.neko-chip-warn { background: #fef3c7; color: #92400e; border-color: #fde68a; }
.neko-chip-bad { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
.neko-chip-diag { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; text-decoration: none; cursor: pointer; }
.neko-chip-diag:hover { background: #bae6fd; text-decoration: none; color: #0c4a6e; }
.neko-copilot-toolbar {
    padding: 10px 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    background: #ffffff;
}
.neko-btn-primary { background: #0284c7; color: #fff; border-color: #0369a1; font-weight: 600; }
.neko-btn-primary:hover { background: #0369a1; color: #fff; }
.neko-btn-solve { background: #059669; color: #fff; border-color: #047857; font-weight: 600; }
.neko-btn-solve:hover { background: #047857; color: #fff; }
.neko-btn-ghost { background: #f8fafc; border-color: #cbd5e1; color: #334155; font-weight: 500; }
.neko-btn-ghost:hover { background: #e2e8f0; color: #0f172a; }
.neko-lang-select { width: 120px !important; display: inline-block; height: 30px; }
.neko-custom-input { flex: 1 1 200px; min-width: 180px; max-width: 320px; height: 30px; }
.neko-copilot-status {
    padding: 7px 14px;
    background: #eff6ff;
    border-top: 1px solid #dbeafe;
    color: #1e40af;
    font-size: 12px;
}
.neko-agent-trace {
    padding: 10px 14px;
    background: #f0f9ff;
    border-top: 1px solid #bae6fd;
    font-size: 12px;
}
.neko-staff-panel {
    border-top: 1px solid #cbd5e1;
    background: #f8fafc;
}
.neko-staff-panel-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 14px;
    background: #e2e8f0;
    color: #0f172a;
    font-size: 12px;
}
.neko-staff-panel-body {
    margin: 0;
    padding: 12px 14px;
    background: #ffffff;
    border: none;
    border-radius: 0;
    max-height: 260px;
    overflow-y: auto;
    white-space: pre-wrap;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, monospace;
    font-size: 12px;
    color: #1e293b;
}
</style>
HTML;

        return str_replace('__TICKET_ID__', (string) $ticket_id, $html);
    }
});


// The AJAX request is now handled natively via gemini_ai_assistant_clientarea in the main module file.

// Hook to inject modern AI Assistant UI in Client Area (Submit Ticket + View/Reply Ticket)
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $filename = $vars['filename'] ?? '';
    if ($filename !== 'viewticket' && $filename !== 'submitticket') {
        return '';
    }

    $page_mode = $filename === 'submitticket' ? 'submit' : 'view';

    $html = <<<'HTML'
<script>
$(document).ready(function() {
    var pageMode = '__PAGE_MODE__';
    if ($('#nekoClientAssistant').length > 0) return;

    var target = $('#inputMessage');
    if (target.length === 0) target = $('textarea[name="replymessage"]');
    if (target.length === 0) target = $('textarea[name="message"]');
    if (target.length === 0) return;

    var insertAnchor = target.closest('.md-editor');
    if (insertAnchor.length === 0) insertAnchor = target;

    var urlParams = new URLSearchParams(window.location.search);
    var tidMask = urlParams.get('tid') || '';
    var ticketC = urlParams.get('c') || '';

    function getToken() {
        return (typeof csrfToken !== 'undefined' ? csrfToken : ($('input[name="token"]').val() || ''));
    }

    function getClientMde() {
        return (typeof target.data('markdown') !== 'undefined') ? target.data('markdown') : null;
    }

    function getClientMessage() {
        var mde = getClientMde();
        return mde ? mde.getContent() : (target.val() || '');
    }

    function setClientMessage(txt) {
        var mde = getClientMde();
        if (mde) {
            mde.setContent(txt);
        } else {
            target.val(txt);
        }
    }

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function(m) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
        });
    }

    var uiHtml = '';
    if (pageMode === 'submit') {
        uiHtml = `
            <div id="nekoClientAssistant" class="neko-client-card">
                <div class="neko-client-head">
                    <div class="neko-client-title">
                        <span class="neko-client-badge"><i class="fas fa-robot"></i></span>
                        <div>
                            <strong>Neko AI Instant Troubleshooter &amp; Ticket Writer</strong>
                            <small>Check your service status, DNS &amp; SSL instantly—or let AI structure your issue clearly.</small>
                        </div>
                    </div>
                    <select id="nekoClientLang" class="neko-client-select" title="Language">
                        <option value="auto">Auto Language</option>
                        <option value="english">English</option>
                        <option value="bangla">Bangla (বাংলা)</option>
                    </select>
                </div>

                <div class="neko-client-templates">
                    <span class="neko-tpl-label">Quick Issue Templates:</span>
                    <button type="button" class="neko-tpl-chip" onclick="nekoApplySubmitTemplate('site_down')"><i class="fas fa-exclamation-circle"></i> Website Down / 5xx</button>
                    <button type="button" class="neko-tpl-chip" onclick="nekoApplySubmitTemplate('email')"><i class="fas fa-envelope"></i> Email Issue</button>
                    <button type="button" class="neko-tpl-chip" onclick="nekoApplySubmitTemplate('ssl')"><i class="fas fa-lock"></i> SSL / HTTPS</button>
                    <button type="button" class="neko-tpl-chip" onclick="nekoApplySubmitTemplate('dns')"><i class="fas fa-globe"></i> DNS / Nameservers</button>
                    <button type="button" class="neko-tpl-chip" onclick="nekoApplySubmitTemplate('billing')"><i class="fas fa-file-invoice-dollar"></i> Billing / Invoice</button>
                </div>

                <div class="neko-client-actions">
                    <button type="button" class="neko-cbtn neko-cbtn-diagnose" onclick="nekoClientSubmitDiagnose()">
                        <i class="fas fa-stethoscope"></i> Instant Service &amp; DNS Check (Solve Now)
                    </button>
                    <button type="button" class="neko-cbtn neko-cbtn-primary" onclick="nekoClientSubmitAssist('polish_ticket')">
                        <i class="fas fa-magic"></i> Polish My Description
                    </button>
                    <button type="button" class="neko-cbtn" onclick="nekoClientSubmitAssist('structure_bug_report')">
                        <i class="fas fa-clipboard-list"></i> Structure Issue Details
                    </button>
                    <button type="button" class="neko-cbtn" onclick="nekoClientSubmitAssist('suggest_subject')">
                        <i class="fas fa-heading"></i> Suggest Subject
                    </button>
                    <button type="button" class="neko-cbtn" onclick="nekoClientSubmitAssist('format_logs')">
                        <i class="fas fa-code"></i> Format Error Logs
                    </button>
                </div>

                <div id="nekoClientStatus" class="neko-client-status" style="display:none;"></div>
                <div id="nekoClientResultPanel" class="neko-client-result" style="display:none;"></div>
            </div>
        `;
    } else {
        uiHtml = `
            <div id="nekoClientAssistant" class="neko-client-card">
                <div class="neko-client-head">
                    <div class="neko-client-title">
                        <span class="neko-client-badge"><i class="fas fa-robot"></i></span>
                        <div>
                            <strong>Neko AI Reply Assistant</strong>
                            <small>Run a live service check, summarize this thread, or polish your message.</small>
                        </div>
                    </div>
                    <div class="neko-client-head-right">
                        <select id="nekoClientLang" class="neko-client-select" title="Language">
                            <option value="auto">Auto Language</option>
                            <option value="english">English</option>
                            <option value="bangla">Bangla (বাংলা)</option>
                        </select>
                        <button type="button" class="neko-cbtn neko-cbtn-human" onclick="nekoClientRequestHuman()" title="Request a human support engineer">
                            <i class="fas fa-user-headset"></i> Live Agent
                        </button>
                    </div>
                </div>

                <div class="neko-client-actions">
                    <button type="button" class="neko-cbtn neko-cbtn-primary" onclick="generateClientAIResponse('generate')">
                        <i class="fas fa-magic"></i> Suggest Reply
                    </button>
                    <button type="button" class="neko-cbtn neko-cbtn-diagnose" onclick="nekoClientViewDiagnose()">
                        <i class="fas fa-stethoscope"></i> Run Live Service / DNS Check
                    </button>
                    <button type="button" class="neko-cbtn" onclick="generateClientAIResponse('summarize')">
                        <i class="fas fa-list-ul"></i> Summarize Thread
                    </button>
                    <button type="button" class="neko-cbtn" onclick="generateClientAIResponse('format_logs')">
                        <i class="fas fa-code"></i> Format Error Logs
                    </button>

                    <div class="neko-cmenu-wrap">
                        <button type="button" class="neko-cbtn" onclick="$('#nekoClientToneMenu').toggle()">
                            <i class="fas fa-pen-nib"></i> Rewrite / Polish <i class="fas fa-caret-down"></i>
                        </button>
                        <div id="nekoClientToneMenu" class="neko-cmenu" style="display:none;">
                            <a href="javascript:void(0)" onclick="$('#nekoClientToneMenu').hide(); generateClientAIResponse('rewrite')">Clear &amp; Polite Polish</a>
                            <a href="javascript:void(0)" onclick="$('#nekoClientToneMenu').hide(); generateClientAIResponse('rewrite_fix_grammar')">Fix Grammar</a>
                            <a href="javascript:void(0)" onclick="$('#nekoClientToneMenu').hide(); generateClientAIResponse('rewrite_formal')">Formal Tone</a>
                            <a href="javascript:void(0)" onclick="$('#nekoClientToneMenu').hide(); generateClientAIResponse('rewrite_friendly')">Friendly Tone</a>
                            <a href="javascript:void(0)" onclick="$('#nekoClientToneMenu').hide(); generateClientAIResponse('rewrite_firm')">Firm / Urgent</a>
                            <a href="javascript:void(0)" onclick="$('#nekoClientToneMenu').hide(); generateClientAIResponse('rewrite_shorter')">Make Shorter</a>
                            <a href="javascript:void(0)" onclick="$('#nekoClientToneMenu').hide(); generateClientAIResponse('rewrite_longer')">Expand Details</a>
                        </div>
                    </div>
                </div>

                <div id="nekoClientStatus" class="neko-client-status" style="display:none;"></div>
                <div id="nekoClientResultPanel" class="neko-client-result" style="display:none;"></div>
            </div>
        `;
    }

    insertAnchor.before(uiHtml);

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.neko-cmenu-wrap').length) {
            $('#nekoClientToneMenu').hide();
        }
    });

    function showClientStatus(msg) {
        $('#nekoClientStatus').html('<i class="fas fa-spinner fa-spin"></i> ' + msg).show();
    }

    function hideClientStatus() {
        $('#nekoClientStatus').hide();
    }

    function renderClientResultPanel(title, bodyHtml, insertText) {
        var insertBtn = insertText
            ? '<button type="button" class="neko-cbtn neko-cbtn-primary" id="nekoClientInsertBtn"><i class="fas fa-level-down-alt"></i> Insert into Message</button>'
            : '';
        var html = '<div class="neko-result-head"><strong>' + esc(title) + '</strong><div>' + insertBtn + ' <button type="button" class="neko-cbtn" onclick="$(\'#nekoClientResultPanel\').slideUp(150)">Close</button></div></div>' +
                   '<div class="neko-result-body">' + bodyHtml + '</div>';
        $('#nekoClientResultPanel').html(html).slideDown(150);
        if (insertText) {
            $('#nekoClientInsertBtn').on('click', function() {
                var cur = getClientMessage().trim();
                setClientMessage(cur ? (cur + "\n\n" + insertText) : insertText);
            });
        }
    }

    window.nekoApplySubmitTemplate = function(type) {
        var templates = {
            site_down: {
                subject: 'Website Down / Returning Error on Domain: ',
                body: "Affected Domain / URL: \nError Code or Message Seen (e.g. 500, 502, Connection Timed Out): \nWhen did the issue start?: \nRecent changes made (plugins, DNS, PHP version, etc.): \nSteps already tried: "
            },
            email: {
                subject: 'Email Sending / Receiving Issue for: ',
                body: "Affected Email Address(es): \nIssue Type (Cannot Send / Cannot Receive / Bounce Error): \nExact Bounce or SMTP Error Message: \nEmail Client Used (Webmail, Outlook, Apple Mail, Phone): "
            },
            ssl: {
                subject: 'SSL / HTTPS Certificate Issue on: ',
                body: "Domain / Subdomain: \nBrowser Warning / Error Message: \nUsing Cloudflare or Direct DNS?: \nWhen was the domain pointed to the server?: "
            },
            dns: {
                subject: 'DNS / Nameserver Configuration Help for: ',
                body: "Domain Name: \nWhat I am trying to point/configure (Website, MX, TXT/SPF, Subdomain): \nCurrent Nameservers / Records: "
            },
            billing: {
                subject: 'Billing / Invoice Question - Invoice #',
                body: "Related Invoice # or Service: \nPayment Method Used: \nTransaction ID / Date (if paid): \nDetails of my request: "
            }
        };
        var tpl = templates[type];
        if (!tpl) return;
        var subjInput = $('#inputSubject');
        if (subjInput.length === 0) subjInput = $('input[name="subject"]');
        if (subjInput.length > 0 && !subjInput.val().trim()) {
            subjInput.val(tpl.subject);
        }
        var cur = getClientMessage().trim();
        if (!cur || confirm('Replace current message draft with this structured template?')) {
            setClientMessage(tpl.body);
        }
    };

    window.nekoClientSubmitDiagnose = function() {
        var subj = ($('#inputSubject').val() || $('input[name="subject"]').val() || '');
        var relSvc = ($('#inputRelatedService').val() || $('select[name="relatedservice"]').val() || '');
        var msg = getClientMessage();

        showClientStatus('Checking your service status, invoices, DNS, SSL &amp; Knowledge Base...');
        $.post('index.php?m=gemini_ai_assistant', {
            token: getToken(),
            action: 'client_submit_diagnose',
            related_service: relSvc,
            subject: subj,
            message: msg,
            reply_lang: $('#nekoClientLang').val() || 'auto'
        }, function(res) {
            hideClientStatus();
            if (!res || !res.success) {
                alert('Diagnostic check could not complete: ' + (res ? res.error : 'Unknown error'));
                return;
            }
            var body = '';
            if (res.ai_guidance) {
                body += '<div class="neko-diag-guidance">' + esc(res.ai_guidance).replace(/\n/g, '<br>') + '</div>';
            }
            if (res.findings && res.findings.length > 0) {
                body += '<ul class="neko-diag-list">';
                res.findings.forEach(function(f) {
                    body += '<li><strong>' + esc(f.label) + ':</strong> ' + esc(f.detail) + '</li>';
                });
                body += '</ul>';
            }
            if (res.kb_articles && res.kb_articles.length > 0) {
                body += '<div class="neko-kb-box"><strong>Helpful Knowledge Base Articles:</strong><ul>';
                res.kb_articles.forEach(function(k) {
                    body += '<li><strong>' + esc(k.title) + '</strong></li>';
                });
                body += '</ul></div>';
            }
            renderClientResultPanel('Instant Diagnostic & Self-Service Check', body || 'No issues detected on our end.', res.report_text || '');
        }, 'json').fail(function() {
            hideClientStatus();
            alert('Could not run diagnostic check. Please try again.');
        });
    };

    window.nekoClientSubmitAssist = function(mode) {
        var subjEl = $('#inputSubject');
        if (subjEl.length === 0) subjEl = $('input[name="subject"]');
        var subj = subjEl.val() || '';
        var msg = getClientMessage();

        if (!msg.trim() && !subj.trim()) {
            alert('Please type a short note or subject first so Neko AI can help improve it.');
            return;
        }

        showClientStatus('Writing and clarifying your ticket...');
        $.post('index.php?m=gemini_ai_assistant', {
            token: getToken(),
            action: 'client_submit_assist',
            assist_mode: mode,
            subject: subj,
            message: msg,
            reply_lang: $('#nekoClientLang').val() || 'auto'
        }, function(res) {
            hideClientStatus();
            if (res && res.success) {
                if (mode === 'suggest_subject' && subjEl.length > 0) {
                    subjEl.val(res.response);
                } else {
                    setClientMessage(res.response);
                }
            } else {
                alert('AI Error: ' + (res ? res.error : 'Unknown error'));
            }
        }, 'json').fail(function() {
            hideClientStatus();
            alert('Could not communicate with AI Assistant.');
        });
    };

    window.nekoClientViewDiagnose = function() {
        showClientStatus('Running live service, DNS &amp; SSL check for this ticket...');
        $.post('index.php?m=gemini_ai_assistant', {
            token: getToken(),
            action: 'client_viewticket_diagnose',
            tid_mask: tidMask,
            c: ticketC,
            reply_lang: $('#nekoClientLang').val() || 'auto'
        }, function(res) {
            hideClientStatus();
            if (!res || !res.success) {
                alert('Diagnostic error: ' + (res ? res.error : 'Unknown error'));
                return;
            }
            var body = '';
            if (res.ai_guidance) {
                body += '<div class="neko-diag-guidance">' + esc(res.ai_guidance).replace(/\n/g, '<br>') + '</div>';
            }
            if (res.findings && res.findings.length > 0) {
                body += '<ul class="neko-diag-list">';
                res.findings.forEach(function(f) {
                    body += '<li><strong>' + esc(f.label) + ':</strong> ' + esc(f.detail) + '</li>';
                });
                body += '</ul>';
            }
            renderClientResultPanel('Live Diagnostic Report', body || 'Checks completed.', res.report_text || '');
        }, 'json').fail(function() {
            hideClientStatus();
            alert('Could not run live diagnostic.');
        });
    };

    window.nekoClientRequestHuman = function() {
        if (!confirm('Insert a request to speak directly with a human support engineer?')) return;
        var cur = getClientMessage().trim();
        var phrase = 'Please connect me to a live agent / human support engineer to review this issue.';
        setClientMessage(cur ? (cur + "\n\n" + phrase) : phrase);
        target.focus();
    };

    window.generateClientAIResponse = function(mode, lang) {
        var currentMessage = getClientMessage();
        var chosenLang = lang || $('#nekoClientLang').val() || 'auto';
        var isPanelMode = (mode === 'summarize');
        var originalContent = currentMessage;

        showClientStatus(isPanelMode ? 'Summarizing ticket conversation...' : 'Generating response...');
        if (!isPanelMode) {
            setClientMessage('AI is preparing your message... Please wait.');
        }

        $.post('index.php?m=gemini_ai_assistant', {
            token: getToken(),
            action: 'client_generate_ai_reply',
            tid_mask: tidMask,
            c: ticketC,
            reply_mode: mode,
            reply_lang: chosenLang,
            current_message: currentMessage
        }, function(response) {
            hideClientStatus();
            if (response && response.success) {
                if (isPanelMode) {
                    renderClientResultPanel('Ticket Conversation Summary', '<div>' + esc(response.response).replace(/\n/g, '<br>') + '</div>', '');
                } else {
                    setClientMessage(response.response);
                }
            } else {
                if (!isPanelMode) setClientMessage(originalContent);
                alert('AI Error: ' + (response ? response.error : 'Unknown error'));
            }
        }, 'json').fail(function() {
            hideClientStatus();
            if (!isPanelMode) setClientMessage(originalContent);
            alert('Could not communicate with the AI. Please try again.');
        });
    };
});
</script>
<style>
.neko-client-card {
    margin: 12px 0 16px 0;
    background: #ffffff;
    border: 1px solid #dbe4ee;
    border-radius: 10px;
    box-shadow: 0 3px 14px rgba(15, 23, 42, 0.06);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    overflow: visible;
}
.neko-client-head {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 11px 15px;
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
    color: #ffffff;
    border-radius: 9px 9px 0 0;
}
.neko-client-title {
    display: flex;
    align-items: center;
    gap: 10px;
}
.neko-client-title small {
    display: block;
    color: #cbd5e1;
    font-size: 11px;
}
.neko-client-badge {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(56, 189, 248, 0.22);
    color: #38bdf8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
}
.neko-client-head-right {
    display: flex;
    align-items: center;
    gap: 8px;
}
.neko-client-select {
    background: rgba(255,255,255,0.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 12px;
}
.neko-client-select option {
    color: #0f172a;
    background: #fff;
}
.neko-client-templates {
    padding: 8px 15px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}
.neko-tpl-label {
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    margin-right: 4px;
}
.neko-tpl-chip {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 11px;
    cursor: pointer;
    transition: all .15s ease;
}
.neko-tpl-chip:hover {
    background: #e0f2fe;
    border-color: #38bdf8;
    color: #0369a1;
}
.neko-client-actions {
    padding: 10px 15px;
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    align-items: center;
}
.neko-cbtn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 11px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    color: #1e293b;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s ease;
}
.neko-cbtn:hover { background: #e2e8f0; }
.neko-cbtn-primary { background: #0284c7; border-color: #0369a1; color: #fff; }
.neko-cbtn-primary:hover { background: #0369a1; }
.neko-cbtn-diagnose { background: #059669; border-color: #047857; color: #fff; }
.neko-cbtn-diagnose:hover { background: #047857; }
.neko-cbtn-human { background: rgba(255,255,255,0.14); border-color: rgba(255,255,255,0.3); color: #fff; }
.neko-cbtn-human:hover { background: rgba(255,255,255,0.25); }
.neko-cmenu-wrap { position: relative; display: inline-block; }
.neko-cmenu {
    position: absolute;
    top: 100%;
    left: 0;
    z-index: 9999;
    min-width: 185px;
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.14);
    padding: 5px 0;
    margin-top: 4px;
}
.neko-cmenu a {
    display: block;
    padding: 7px 14px;
    font-size: 12px;
    color: #1e293b;
    text-decoration: none;
}
.neko-cmenu a:hover {
    background: #f1f5f9;
    color: #0284c7;
}
.neko-client-status {
    padding: 8px 15px;
    background: #eff6ff;
    border-top: 1px solid #dbeafe;
    color: #1e40af;
    font-size: 12px;
}
.neko-client-result {
    border-top: 1px solid #cbd5e1;
    background: #f8fafc;
    border-radius: 0 0 9px 9px;
}
.neko-result-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 15px;
    background: #e2e8f0;
    color: #0f172a;
    font-size: 12px;
}
.neko-result-body {
    padding: 12px 15px;
    font-size: 13px;
    color: #1e293b;
    line-height: 1.5;
}
.neko-diag-guidance {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 10px 12px;
    margin-bottom: 10px;
}
.neko-diag-list {
    margin: 6px 0 8px 18px;
    padding: 0;
}
.neko-kb-box {
    margin-top: 8px;
    padding: 8px 12px;
    background: #f0f9ff;
    border-left: 3px solid #0284c7;
    border-radius: 4px;
}
</style>
HTML;

    return str_replace('__PAGE_MODE__', $page_mode, $html);
});


add_hook('AdminAreaHeaderOutput', 1, function ($vars) {
    if ($vars['filename'] == 'addonmodules' && $_GET['module'] == 'gemini_ai_assistant' && isset($_POST['action']) && $_POST['action'] == 'get_log_details') {
        $log_id = (int) $_POST['log_id'];
        $log = Capsule::table('mod_gemini_api_logs')->where('id', $log_id)->first();

        if ($log) {
            echo '<div class="log-details">';
            echo '<h4>Request Data:</h4>';
            echo '<pre style="background: #f8f9fa; padding: 10px; border-radius: 5px; max-height: 200px; overflow-y: auto;">';
            echo htmlspecialchars($log->request_data);
            echo '</pre>';

            echo '<h4>Response Data:</h4>';
            echo '<pre style="background: #f8f9fa; padding: 10px; border-radius: 5px; max-height: 200px; overflow-y: auto;">';
            echo htmlspecialchars($log->response_data);
            echo '</pre>';

            echo '<h4>Technical Details:</h4>';
            echo '<table class="table table-bordered">';
            echo '<tr><td><strong>CURL Error:</strong></td><td>' . ($log->curl_error ? htmlspecialchars($log->curl_error) : 'None') . '</td></tr>';
            echo '<tr><td><strong>Error Details:</strong></td><td>' . ($log->error_details ? htmlspecialchars($log->error_details) : 'None') . '</td></tr>';
            echo '<tr><td><strong>Endpoint:</strong></td><td>' . htmlspecialchars($log->api_endpoint) . '</td></tr>';
            echo '</table>';
            echo '</div>';
        } else {
            echo 'Log entry not found.';
        }
        exit;
    }
});

// Core Functions
function get_gemini_config()
{
    $settings = Capsule::table('tbladdonmodules')
        ->where('module', 'gemini_ai_assistant')
        ->pluck('value', 'setting')
        ->toArray(); // Ensure associative array

    $isOn = function ($key, $default = false) use ($settings) {
        if (!array_key_exists($key, $settings)) {
            return $default;
        }
        $val = strtolower(trim((string) $settings[$key]));
        return in_array($val, ['1', 'on', 'yes', 'true'], true);
    };

    return [
        // Core API Settings
        'gemini_api_key' => $settings['gemini_api_key'] ?? '',
        'gemini_model' => !empty($settings['gemini_model']) ? trim($settings['gemini_model']) : 'gemini-2.5-flash',
        'gemini_fallback_model' => !empty($settings['gemini_fallback_model']) ? trim($settings['gemini_fallback_model']) : '',
        'assistant_name' => !empty($settings['assistant_name']) ? $settings['assistant_name'] : 'AI Support',
        'auto_reply_enabled' => $isOn('auto_reply_enabled', true),
        'confidence_threshold' => (float) ($settings['confidence_threshold'] ?? 0.7),
        'max_tokens' => (int) ($settings['max_tokens'] ?? 2048),
        'temperature' => (float) ($settings['temperature'] ?? 0.4),
        'custom_instructions' => $settings['custom_instructions'] ?? '',
        'training_tickets_limit' => (int) ($settings['training_tickets_limit'] ?? 1000),
        'enable_debug_logging' => $isOn('enable_debug_logging', true),

        // Footer Controls
        'ai_footer_enabled' => $isOn('ai_footer_enabled', true),
        'ai_footer_text' => $settings['ai_footer_text'] ?? "---\n**This response was generated by AI Assistant**",
        'ai_confidence_enabled' => $isOn('ai_confidence_enabled', false),

        // Training & Customer Insights
        'enable_ticket_training' => $isOn('enable_ticket_training', true),
        'enable_customer_insights' => $isOn('enable_customer_insights', true),

        // Admin Escalation
        'admin_notification_email' => $settings['admin_notification_email'] ?? '',
        'escalation_auto_notify' => $isOn('escalation_auto_notify', true),

        // Autopilot & Context Settings (previously omitted!)
        'autopilot_departments' => $settings['autopilot_departments'] ?? '',
        'autopilot_delay' => (int) ($settings['autopilot_delay'] ?? 0),
        'autopilot_status' => !empty($settings['autopilot_status']) ? $settings['autopilot_status'] : 'Answered',
        'include_admin_notes' => $isOn('include_admin_notes', true),

        // Autonomous Agent Controls
        'agent_enabled' => $isOn('agent_enabled', true),
        'agent_allow_safe_actions' => $isOn('agent_allow_safe_actions', true),
        'agent_allow_service_actions' => $isOn('agent_allow_service_actions', false),
        'agent_allow_panel_checks' => $isOn('agent_allow_panel_checks', true),
        'agent_uncertain_behavior' => !empty($settings['agent_uncertain_behavior']) ? $settings['agent_uncertain_behavior'] : 'draft_note',
        'agent_max_steps' => max(2, min(10, (int) ($settings['agent_max_steps'] ?? 6))),
    ];
}




function should_skip_ai_reply($message)
{
    $skip_phrases = [
        'talk to agent',
        'speak to human',
        'real person',
        'human agent',
        'live agent',
        'customer service',
        'get me a person',
        'not a bot',
        'talk to admin',
        'speak to admin',
        'contact admin',
        'need admin',
        'want admin',
        'manager',
        'supervisor',
        'escalate',
        'speak to someone',
        'talk to someone real',
        'connect me to admin',
        'direct response from admin',
        'admin response',
        'human support',
        'real support',
        'transfer to human',
        'stop bot',
        'no bot',
        'i want a human',
        'let me talk to a person'
    ];

    $message_lower = strtolower($message);

    foreach ($skip_phrases as $phrase) {
        if (strpos($message_lower, $phrase) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Get comprehensive customer profile data for AI context
 */
function get_customer_profile($client_id)
{
    if (empty($client_id)) {
        return null;
    }

    try {
        // Personal Information
        $client = Capsule::table('tblclients')
            ->where('id', $client_id)
            ->first();

        if (!$client) {
            return null;
        }

        // Ticket History
        $total_tickets = Capsule::table('tbltickets')
            ->where('userid', $client_id)
            ->count();

        $tickets_by_status = Capsule::table('tbltickets')
            ->where('userid', $client_id)
            ->select('status', Capsule::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Spending Data - Total paid invoices
        $total_spent = Capsule::table('tblinvoices')
            ->where('userid', $client_id)
            ->where('status', 'Paid')
            ->sum('total');

        // Active Products/Services
        $active_products = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->where('tblhosting.userid', $client_id)
            ->where('tblhosting.domainstatus', 'Active')
            ->select('tblproducts.name', 'tblhosting.domain', 'tblhosting.amount', 'tblhosting.billingcycle', 'tblhosting.nextduedate')
            ->get();

        $all_products_count = Capsule::table('tblhosting')
            ->where('userid', $client_id)
            ->count();

        // Active Domains
        $active_domains = Capsule::table('tbldomains')
            ->where('userid', $client_id)
            ->where('status', 'Active')
            ->select('domain', 'recurringamount', 'nextduedate')
            ->get();

        $all_domains_count = Capsule::table('tbldomains')
            ->where('userid', $client_id)
            ->count();

        // Recent ticket dates
        $first_ticket_date = Capsule::table('tbltickets')
            ->where('userid', $client_id)
            ->orderBy('date', 'asc')
            ->value('date');

        $last_ticket_date = Capsule::table('tbltickets')
            ->where('userid', $client_id)
            ->orderBy('date', 'desc')
            ->value('date');

        return [
            'personal' => [
                'name' => trim($client->firstname . ' ' . $client->lastname),
                'email' => $client->email ?? '',
                'company' => $client->companyname ?? '',
                'phone' => $client->phonenumber ?? '',
                'country' => $client->country ?? '',
                'city' => $client->city ?? '',
                'state' => $client->state ?? '',
                'status' => $client->status ?? 'Active',
                'date_created' => $client->datecreated ?? '',
                'language' => $client->language ?? 'english',
            ],
            'tickets' => [
                'total_tickets' => $total_tickets,
                'by_status' => $tickets_by_status,
                'first_ticket' => $first_ticket_date,
                'last_ticket' => $last_ticket_date,
            ],
            'financial' => [
                'total_spent' => number_format((float) $total_spent, 2),
                'currency' => $client->currency ?? 1,
            ],
            'products' => [
                'active_count' => $active_products->count(),
                'total_count' => $all_products_count,
                'active_list' => $active_products->map(function ($p) {
                    return [
                        'name' => $p->name,
                        'domain' => $p->domain,
                        'amount' => $p->amount,
                        'billing' => $p->billingcycle,
                        'next_due' => $p->nextduedate,
                    ];
                })->toArray(),
            ],
            'domains' => [
                'active_count' => $active_domains->count(),
                'total_count' => $all_domains_count,
                'active_list' => $active_domains->map(function ($d) {
                    return [
                        'domain' => $d->domain,
                        'amount' => $d->recurringamount,
                        'next_due' => $d->nextduedate,
                    ];
                })->toArray(),
            ],
        ];
    } catch (\Exception $e) {
        logActivity("Neko AI: Error fetching customer profile for client #$client_id: " . $e->getMessage());
        return null;
    }
}

/**
 * Format customer profile data into text context for AI prompt
 */
function format_customer_context($profile)
{
    if (!$profile) {
        return "No customer profile data available.";
    }

    $p = $profile['personal'];
    $t = $profile['tickets'];
    $f = $profile['financial'];
    $pr = $profile['products'];

    $context = "CUSTOMER PROFILE:\n";
    $context .= "- Name: {$p['name']}\n";
    $context .= "- Email: {$p['email']}\n";
    if (!empty($p['company'])) {
        $context .= "- Company: {$p['company']}\n";
    }
    $context .= "- Phone: {$p['phone']}\n";
    $context .= "- Location: {$p['city']}, {$p['state']}, {$p['country']}\n";
    $context .= "- Account Status: {$p['status']}\n";
    $context .= "- Customer Since: {$p['date_created']}\n";
    $context .= "\nTICKET HISTORY:\n";
    $context .= "- Total Tickets Created: {$t['total_tickets']}\n";
    if (!empty($t['by_status'])) {
        foreach ($t['by_status'] as $status => $count) {
            $context .= "  - {$status}: {$count}\n";
        }
    }
    if ($t['first_ticket']) {
        $context .= "- First Ticket: {$t['first_ticket']}\n";
    }
    if ($t['last_ticket']) {
        $context .= "- Last Ticket: {$t['last_ticket']}\n";
    }
    $context .= "\nFINANCIAL:\n";
    $context .= "- Total Amount Spent: \${$f['total_spent']}\n";
    $context .= "\nPRODUCTS & SERVICES:\n";
    $context .= "- Active Products: {$pr['active_count']}\n";
    $context .= "- Total Products (all time): {$pr['total_count']}\n";
    if (!empty($pr['active_list'])) {
        foreach ($pr['active_list'] as $product) {
            $context .= "  - {$product['name']}";
            if (!empty($product['domain'])) {
                $context .= " ({$product['domain']})";
            }
            $context .= " - \${$product['amount']}/{$product['billing']}";
            $context .= ", Next Due: {$product['next_due']}\n";
        }
    }

    $d = $profile['domains'] ?? [];
    if (!empty($d)) {
        $context .= "\nDOMAINS:\n";
        $context .= "- Active Domains: {$d['active_count']}\n";
        $context .= "- Total Domains (all time): {$d['total_count']}\n";
        if (!empty($d['active_list'])) {
            foreach ($d['active_list'] as $domain) {
                $context .= "  - {$domain['domain']} - \${$domain['amount']}, Next Due: {$domain['next_due']}\n";
            }
        }
    }

    return $context;
}

/**
 * Train AI from historical resolved tickets
 * Extracts Q&A pairs from tickets with admin replies and caches them
 */
function train_from_historical_tickets($department_id = null, $limit = 100)
{
    try {
        $query = Capsule::table('tbltickets')
            ->join('tblticketreplies', 'tbltickets.id', '=', 'tblticketreplies.tid')
            ->where('tblticketreplies.admin', '!=', '')
            ->select(
                'tbltickets.id as ticket_id',
                'tbltickets.did as department_id',
                'tbltickets.title',
                'tbltickets.message as customer_message',
                'tblticketreplies.message as admin_reply'
            )
            ->orderBy('tbltickets.id', 'desc')
            ->limit($limit);

        if ($department_id) {
            $query->where('tbltickets.did', $department_id);
        }

        $tickets = $query->get();

        if ($tickets->isEmpty()) {
            return ['success' => false, 'message' => 'No resolved tickets with admin replies found.', 'count' => 0];
        }

        $processed = 0;
        $skipped_cache = 0;
        $skipped_length = 0;

        foreach ($tickets as $ticket) {
            // Skip if already cached
            $exists = Capsule::table('mod_gemini_training_cache')
                ->where('source_ticket_id', $ticket->ticket_id)
                ->exists();

            if ($exists) {
                $skipped_cache++;
                continue;
            }

            // Extract and clean the data
            $question = strip_tags(trim($ticket->customer_message));
            $answer = strip_tags(trim($ticket->admin_reply));

            // Skip very short or empty entries
            if (strlen($question) < 3 || strlen($answer) < 3) {
                $skipped_length++;
                continue;
            }

            // Determine category from keywords
            $category = categorize_ticket_content($question);

            // Calculate relevance score based on response quality
            $relevance = calculate_training_relevance($question, $answer);

            Capsule::table('mod_gemini_training_cache')->insert([
                'department_id' => $ticket->department_id,
                'category' => $category,
                'question_summary' => substr($question, 0, 500),
                'answer_summary' => substr($answer, 0, 1000),
                'source_ticket_id' => $ticket->ticket_id,
                'relevance_score' => $relevance,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $processed++;
        }

        $message = "Processed {$processed} new tickets. ";
        if ($skipped_cache > 0) $message .= "Skipped {$skipped_cache} already cached. ";
        if ($skipped_length > 0) $message .= "Skipped {$skipped_length} for being too short. ";

        return [
            'success' => true,
            'message' => $message,
            'count' => $processed,
            'total_scanned' => $tickets->count(),
        ];
    } catch (\Exception $e) {
        logActivity("Neko AI Training Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Training failed: ' . $e->getMessage(), 'count' => 0];
    }
}

/**
 * Categorize ticket content for training
 */
function categorize_ticket_content($message)
{
    $message_lower = strtolower($message);
    $categories = [
        'billing' => ['payment', 'invoice', 'bill', 'charge', 'refund', 'subscription', 'renewal', 'price', 'cost'],
        'technical' => ['error', 'bug', 'not working', 'broken', 'issue', 'crash', 'down', '500', '404', 'ssl', 'dns'],
        'account' => ['login', 'password', 'account', 'sign up', 'register', 'profile', 'email change'],
        'hosting' => ['cpanel', 'disk', 'bandwidth', 'database', 'ftp', 'backup', 'migration', 'transfer'],
        'domain' => ['domain', 'nameserver', 'whois', 'dns', 'transfer domain', 'register domain'],
        'sales' => ['upgrade', 'plan', 'pricing', 'discount', 'coupon', 'new service', 'purchase'],
    ];

    foreach ($categories as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($message_lower, $keyword) !== false) {
                return $category;
            }
        }
    }

    return 'general';
}

/**
 * Calculate training relevance score
 */
function calculate_training_relevance($question, $answer)
{
    $score = 0.5; // baseline

    // Longer, detailed answers are more valuable
    if (strlen($answer) > 200)
        $score += 0.15;
    if (strlen($answer) > 500)
        $score += 0.10;

    // Answers with step-by-step instructions
    if (preg_match('/\d+\.\s/', $answer))
        $score += 0.10;

    // Answers containing links (resources)
    if (strpos($answer, 'http') !== false)
        $score += 0.05;

    // Questions that are clear and specific
    if (strlen($question) > 30 && strlen($question) < 300)
        $score += 0.10;

    return min(1.0, $score);
}

/**
 * Escalate ticket to admin: set priority, email admin, log escalation
 */
function escalate_to_admin($ticket_id, $client_id, $reason = 'Customer requested admin contact')
{
    $config = get_gemini_config();

    try {
        // 1. Set ticket priority to High
        Capsule::table('tbltickets')
            ->where('id', $ticket_id)
            ->update([
                'urgency' => 'High',
                'flag' => 1,
                'lastreply' => date('Y-m-d H:i:s'),
            ]);

        // 2. Log the escalation
        Capsule::table('mod_gemini_escalations')->insert([
            'ticket_id' => $ticket_id,
            'client_id' => $client_id,
            'reason' => $reason,
            'priority' => 'high',
            'admin_notified' => false,
            'admin_email_sent_at' => null,
            'resolved' => false,
            'resolved_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 3. Add admin note
        localAPI('AddTicketNote', [
            'ticketid' => $ticket_id,
            'message' => "ESCALATED TO ADMIN - Reason: {$reason}\nCustomer requested direct admin response. Ticket priority set to High."
        ]);

        // 4. Send email to admin if configured
        $email_sent = false;
        if (!empty($config['escalation_auto_notify'])) {
            $email_sent = send_admin_escalation_email($ticket_id, $client_id, $reason, $config);

            if ($email_sent) {
                Capsule::table('mod_gemini_escalations')
                    ->where('ticket_id', $ticket_id)
                    ->orderBy('created_at', 'desc')
                    ->limit(1)
                    ->update([
                        'admin_notified' => true,
                        'admin_email_sent_at' => date('Y-m-d H:i:s'),
                    ]);
            }
        }

        // 5. Update ticket analysis
        Capsule::table('mod_gemini_ticket_analysis')
            ->updateOrInsert(
                ['ticket_id' => $ticket_id],
                [
                    'needs_human' => true,
                    'escalation_level' => 1.0,
                    'analyzed_at' => date('Y-m-d H:i:s'),
                ]
            );

        logActivity("Neko AI: Ticket #{$ticket_id} escalated to admin. Email sent: " . ($email_sent ? 'Yes' : 'No'));

        return ['success' => true, 'email_sent' => $email_sent];
    } catch (\Exception $e) {
        logActivity("Neko AI Escalation Error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Send escalation notification email to admin
 */
function send_admin_escalation_email($ticket_id, $client_id, $reason, $config)
{
    try {
        $ticket = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
        $client = Capsule::table('tblclients')->where('id', $client_id)->first();

        if (!$ticket || !$client) {
            return false;
        }

        $admin_email = $config['admin_notification_email'];
        $assistant_name = $config['assistant_name'] ?? 'Neko AI';
        $client_name = trim($client->firstname . ' ' . $client->lastname);
        $ticket_subject = $ticket->title ?? '(No Subject)';

        $subject = "[{$assistant_name}] Escalation Alert - Ticket #{$ticket->tid}: {$ticket_subject}";

        $body = "<html><body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background: #f4f5f7; padding: 20px;'>";
        $body .= "<div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); overflow: hidden;'>";
        $body .= "<div style='background: linear-gradient(135deg, #e74c3c, #c0392b); padding: 24px 30px; color: white;'>";
        $body .= "<h2 style='margin: 0; font-size: 20px;'>Ticket Escalation Alert</h2>";
        $body .= "<p style='margin: 8px 0 0; opacity: 0.9; font-size: 14px;'>A customer has requested direct admin response</p>";
        $body .= "</div>";
        $body .= "<div style='padding: 30px;'>";
        $body .= "<table style='width: 100%; border-collapse: collapse; font-size: 14px;'>";
        $body .= "<tr><td style='padding: 8px 0; color: #7f8c8d; width: 140px;'>Ticket ID:</td><td style='padding: 8px 0; font-weight: 600;'>#{$ticket->tid}</td></tr>";
        $body .= "<tr><td style='padding: 8px 0; color: #7f8c8d;'>Subject:</td><td style='padding: 8px 0;'>{$ticket_subject}</td></tr>";
        $body .= "<tr><td style='padding: 8px 0; color: #7f8c8d;'>Customer:</td><td style='padding: 8px 0;'>{$client_name} ({$client->email})</td></tr>";
        $body .= "<tr><td style='padding: 8px 0; color: #7f8c8d;'>Reason:</td><td style='padding: 8px 0; color: #e74c3c; font-weight: 600;'>{$reason}</td></tr>";
        $body .= "<tr><td style='padding: 8px 0; color: #7f8c8d;'>Priority:</td><td style='padding: 8px 0;'><span style='background: #e74c3c; color: white; padding: 3px 10px; border-radius: 4px; font-size: 12px;'>HIGH</span></td></tr>";
        $body .= "<tr><td style='padding: 8px 0; color: #7f8c8d;'>Escalated At:</td><td style='padding: 8px 0;'>" . date('M j, Y h:i A') . "</td></tr>";
        $body .= "</table>";
        $body .= "</div>";
        $body .= "<div style='background: #f8f9fa; padding: 16px 30px; text-align: center; font-size: 12px; color: #95a5a6; border-top: 1px solid #eee;'>";
        $body .= "Sent by {$assistant_name} - Automated Escalation System";
        $body .= "</div></div></body></html>";

        $system_email = Capsule::table('tblconfiguration')->where('setting', 'Email')->value('value');
        $from_email = $system_email ? $system_email : "noreply@" . ($_SERVER['SERVER_NAME'] ?? 'localhost');

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$assistant_name} <{$from_email}>\r\n";

        $mail_sent = false;
        if (!empty($admin_email)) {
            $mail_sent = @mail($admin_email, $subject, $body, $headers);
        }

        // Use WHMCS native SendAdminEmail API to notify System Admins
        $apiResult = localAPI('SendAdminEmail', [
            'customsubject' => $subject,
            'custommessage' => $body,
            'type' => 'system'
        ]);

        $api_sent = ($apiResult['result'] === 'success');
        $result = $mail_sent || $api_sent;

        logActivity("Neko AI: Admin escalation email (Mail: " . ($mail_sent ? 'Sent' : 'Failed') . " | API: " . ($api_sent ? 'Sent' : 'Failed') . ") for ticket #{$ticket_id}");

        return $result;
    } catch (\Exception $e) {
        logActivity("Neko AI: Failed to send admin email: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify client that their request for admin contact has been received
 */
function notify_client_escalation($ticket_id, $config)
{
    $assistant_name = !empty($config['assistant_name']) ? $config['assistant_name'] : 'AI Support';

    $notification_message = "Hello,\n\n";
    $notification_message .= "Thank you for reaching out. We understand that you'd like to speak with an admin directly.\n\n";
    $notification_message .= "**Your request has been received and prioritized.**\n\n";
    $notification_message .= "An admin has been notified and will connect with you as soon as possible. ";
    $notification_message .= "Your ticket has been flagged as **High Priority** to ensure prompt attention.\n\n";
    $notification_message .= "We appreciate your patience and will do our best to resolve your concern quickly.\n\n";
    $notification_message .= "Best regards,\n";
    $notification_message .= "**{$assistant_name}** - Automated Support Team";

    // Add footer if enabled
    $footer = '';
    if (!empty($config['ai_footer_enabled'])) {
        $footer_text = str_replace('\\n', "\n", $config['ai_footer_text'] ?? "---\n**This response was generated by AI Assistant**");
        $footer = "\n\n" . trim($footer_text);
    }

    $final_message = trim($notification_message . $footer);

    $admin = Capsule::table('tbladmins')->where('disabled', 0)->first();
    $api_success = false;

    if ($admin) {
        $apiResult = localAPI('AddTicketReply', [
            'ticketid' => $ticket_id,
            'message' => $final_message,
            'adminusername' => $admin->username,
            'markdown' => true,
        ]);
        if ($apiResult['result'] == 'success') {
            $api_success = true;
            Capsule::table('tblticketreplies')
                ->where('tid', $ticket_id)
                ->where('admin', $admin->username)
                ->orderBy('id', 'desc')
                ->limit(1)
                ->update(['admin' => $assistant_name]);
                
            Capsule::table('tbltickets')->where('id', $ticket_id)->update(['status' => 'Open']);
        }
    }

    if (!$api_success) {
        // Fallback: Insert the notification as a ticket reply
        Capsule::table('tblticketreplies')->insert([
            'tid' => $ticket_id,
            'userid' => 0,
            'contactid' => 0,
            'admin' => $assistant_name,
            'message' => $final_message,
            'date' => date('Y-m-d H:i:s'),
            'editor' => 'markdown',
        ]);

        // Update ticket last reply and status
        Capsule::table('tbltickets')
            ->where('id', $ticket_id)
            ->update([
                'lastreply' => date('Y-m-d H:i:s'),
                'status' => 'Open'
            ]);
    }

    logActivity("Neko AI: Client notification sent for escalated ticket #{$ticket_id}");
}

function analyze_ticket_content($message, $ticket_id)
{
    $config = get_gemini_config();

    // Get previous analysis if exists
    $previous_analysis = Capsule::table('mod_gemini_ticket_analysis')
        ->where('ticket_id', $ticket_id)
        ->first();

    // Prepare context from QA pairs
    $qa_context = get_qa_context($message);

    // Mood detection logic
    $mood = detect_mood($message);
    $escalation_level = calculate_escalation($message, $previous_analysis);
    $confidence = calculate_confidence($message, $qa_context);

    // Save analysis
    Capsule::table('mod_gemini_ticket_analysis')->updateOrInsert(
        ['ticket_id' => $ticket_id],
        [
            'mood' => $mood,
            'escalation_level' => $escalation_level,
            'key_issues' => extract_key_issues($message),
            'needs_human' => $escalation_level > 0.8,
            'analyzed_at' => date('Y-m-d H:i:s')
        ]
    );

    return [
        'mood' => $mood,
        'escalation_level' => $escalation_level,
        'confidence' => $confidence,
        'key_issues' => extract_key_issues($message),
        'needs_human' => $escalation_level > 0.8
    ];
}

function detect_mood($message)
{
    $raw = (string) $message;
    $message_lower = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);

    $mood_indicators = [
        'angry' => [
            'angry', 'mad', 'pissed', 'furious', 'outrageous', 'ridiculous',
            'scam', 'useless', 'terrible', 'worst', 'refund me', 'cancel my account',
            'বিরক্ত', 'ফালতু', 'বাজে সেবা'
        ],
        'frustrated' => [
            'frustrated', 'annoying', 'annoyed', 'disappointed', 'fed up', 'sick of',
            'still not working', 'still down', 'same issue', 'same problem', 'already tried',
            'not fixed', 'nobody replied', 'waiting for hours', 'again and again', '3rd time',
            'এখনো ঠিক হয়নি', 'কাজ করছে না', 'একই সমস্যা', 'আগেই বলেছি'
        ],
        'urgent' => [
            'urgent', 'asap', 'emergency', 'immediately', 'right now', 'site is down',
            'website down', 'server down', 'production', 'critical', 'জরুরি', 'এখনই'
        ],
        'satisfied' => ['thanks', 'thank you', 'appreciate', 'great', 'awesome', 'perfect', 'ধন্যবাদ'],
        'calm' => ['hello', 'hi', 'please', 'could you', 'would you', 'kindly'],
        'neutral' => ['question', 'inquiry', 'help with', 'information']
    ];

    $use_mb_strpos = function_exists('mb_strpos');
    $scores = [];
    foreach ($mood_indicators as $mood => $indicators) {
        $score = 0;
        foreach ($indicators as $indicator) {
            $found = $use_mb_strpos
                ? mb_strpos($message_lower, $indicator, 0, 'UTF-8') !== false
                : strpos($message_lower, $indicator) !== false;
            if ($found) {
                $score++;
            }
        }
        $scores[$mood] = $score;
    }

    // Prioritize negative/urgent signals over polite words ("please fix my site, it's been down for hours")
    foreach (['angry', 'urgent', 'frustrated', 'satisfied', 'calm', 'neutral'] as $priority_mood) {
        if (!empty($scores[$priority_mood]) && $scores[$priority_mood] >= 1) {
            if (in_array($priority_mood, ['angry', 'urgent', 'frustrated'], true)) {
                return $priority_mood;
            }
        }
    }

    $detected_mood = 'neutral';
    $max_score = 0;
    foreach ($scores as $mood => $score) {
        if ($score > $max_score) {
            $max_score = $score;
            $detected_mood = $mood;
        }
    }

    return $detected_mood;
}

function calculate_escalation($message, $previous_analysis = null)
{
    $mood = detect_mood($message);
    $base_escalation = [
        'calm' => 0.1,
        'satisfied' => 0.2,
        'neutral' => 0.3,
        'frustrated' => 0.7,
        'urgent' => 0.8,
        'angry' => 0.9
    ];

    $escalation = $base_escalation[$mood] ?? 0.3;

    // Increase escalation if previous analysis shows worsening mood
    if ($previous_analysis) {
        $previous_mood = $previous_analysis->mood;
        $previous_escalation = $previous_analysis->escalation_level;

        $mood_hierarchy = ['calm', 'satisfied', 'neutral', 'frustrated', 'urgent', 'angry'];
        $current_index = array_search($mood, $mood_hierarchy);
        $previous_index = array_search($previous_mood, $mood_hierarchy);

        if ($current_index > $previous_index) {
            $escalation = min(1.0, $previous_escalation + 0.2);
        }
    }

    return $escalation;
}

function calculate_confidence($message, $qa_context)
{
    $message_lower = strtolower($message);
    $max_matches = 0;

    // Check QA context
    foreach ($qa_context as $qa) {
        if (is_object($qa) && isset($qa->question)) {
            similar_text($message_lower, strtolower($qa->question), $percent);
            if ($percent > $max_matches) {
                $max_matches = $percent;
            }
        }
    }

    // Check Training Cache for similarity (new feature)
    try {
        $training_data = Capsule::table('mod_gemini_training_cache')
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();

        foreach ($training_data as $td) {
            similar_text($message_lower, strtolower($td->question_summary), $percent);
            if ($percent > $max_matches) {
                $max_matches = $percent;
            }
        }
    } catch (\Exception $e) {
        // Ignore if table doesn't exist yet
    }

    // Convert to 0-1 scale. 
    // We set a higher baseline (0.5 instead of 0.3) so AI attempts to answer more general queries
    // if the threshold is set to 0.5.
    $confidence = max(0.5, $max_matches / 100);

    return $confidence;
}

function get_qa_context($message)
{
    return Capsule::table('mod_gemini_qa_pairs')
        ->where('active', true)
        ->orderBy('priority', 'desc')
        ->limit(10)
        ->get()
        ->toArray();
}

function extract_key_issues($message)
{
    // Simple keyword extraction - can be enhanced with NLP
    $keywords = [
        'billing' => ['payment', 'invoice', 'bill', 'charge', 'refund'],
        'technical' => ['error', 'bug', 'not working', 'broken', 'issue'],
        'account' => ['login', 'password', 'account', 'sign up'],
        'service' => ['downtime', 'slow', 'performance', 'speed']
    ];

    $issues = [];
    $message_lower = strtolower($message);

    foreach ($keywords as $category => $terms) {
        foreach ($terms as $term) {
            if (strpos($message_lower, $term) !== false) {
                $issues[] = $category;
                break;
            }
        }
    }

    return implode(', ', array_unique($issues));
}

function generate_ai_ticket_response($message, $ticket_id, $analysis, $mode = 'generate', $current_draft = '', $lang = 'auto', $role = 'admin')
{
    $config = get_gemini_config();

    if (empty($config['gemini_api_key'])) {
        logActivity("AI Assistant: Gemini API key not configured");
        return ['success' => false, 'error' => 'API key not configured'];
    }

    try {
        // Prepare prompt with context and mode
        $prompt = build_ai_prompt($message, $ticket_id, $analysis, $config, $mode, $current_draft, $lang, $role);

        // Call Gemini API (get real AI response + confidence)
        $response = call_gemini_api($prompt, $config, 'ticket_reply', $ticket_id);

        if ($response['success']) {
            // ✅ Prefer the model’s own confidence value if available
            $actual_confidence = isset($response['confidence']) && $response['confidence'] !== null
                ? (float) $response['confidence']
                : (float) ($analysis['confidence'] ?? 0.5);

            // ✅ Log the AI interaction
            Capsule::table('mod_gemini_ai_logs')->insert([
                'ticket_id' => $ticket_id,
                'user_message' => $mode == 'generate' ? $message : "Draft: " . substr($current_draft, 0, 100),
                'ai_response' => $response['response'],
                'confidence_score' => $actual_confidence,
                'mood_detected' => $analysis['mood'],
                'response_type' => $mode,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // ✅ Return clean human-readable response only
            return [
                'success' => true,
                'response' => $response['response'],
                'confidence' => $actual_confidence,
                'mood' => $analysis['mood']
            ];
        }

        // API returned error
        return [
            'success' => false,
            'error' => $response['error'] ?? 'Unknown error from Gemini API'
        ];

    } catch (Exception $e) {
        logActivity("AI Assistant Error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


function build_ai_prompt($message, $ticket_id, $analysis, $config, $mode = 'generate', $current_draft = '', $lang = 'auto', $role = 'admin')
{
    // Fetch main ticket info
    $ticket_data = Capsule::table('tbltickets')
        ->where('id', $ticket_id)
        ->first();

    if (!$ticket_data) {
        return $message; // fallback
    }

    // Try to get customer name
    if (!empty($ticket_data->name)) {
        $customer_name = $ticket_data->name;
    } elseif (!empty($ticket_data->userid)) {
        $customer_name = Capsule::table('tblclients')
            ->where('id', $ticket_data->userid)
            ->value(Capsule::raw("CONCAT(firstname, ' ', lastname)"));
    } else {
        $customer_name = 'Valued Customer';
    }

    // Ticket subject & department
    $ticket_subject = $ticket_data->title ?? '(No Subject)';
    $department_name = Capsule::table('tblticketdepartments')
        ->where('id', $ticket_data->did)
        ->value('name') ?? 'General Support';

    // Knowledge base (QA pairs & WHMCS KB)
    $qa_pairs = Capsule::table('mod_gemini_qa_pairs')
        ->where('active', true)
        ->orderBy('priority', 'desc')
        ->limit(5)
        ->get();

    $qa_context = "";
    foreach ($qa_pairs as $qa) {
        $qa_context .= "Q: {$qa->question}\nA: {$qa->answer}\n\n";
    }

    // Append WHMCS KB search results
    if (function_exists('search_whmcs_kb')) {
        $kb_articles = search_whmcs_kb($message);
        if ($kb_articles->isNotEmpty()) {
            $qa_context .= "\nOFFICIAL KNOWLEDGE BASE ARTICLES:\n";
            foreach ($kb_articles as $article) {
                $qa_context .= "TITLE: {$article->title}\nCONTENT: " . trim(strip_tags($article->article)) . "\n\n";
            }
        }
    }

    // Customer Profile Data (new feature)
    $customer_context = "";
    if (!empty($config['enable_customer_insights']) && !empty($ticket_data->userid)) {
        $profile = get_customer_profile($ticket_data->userid);
        $customer_context = format_customer_context($profile);
    }

    // Ticket Custom Fields
    $custom_fields_context = "";
    try {
        $custom_fields = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
            ->where('tblcustomfields.type', 'support')
            ->where('tblcustomfieldsvalues.relid', $ticket_id)
            ->select('tblcustomfields.fieldname', 'tblcustomfieldsvalues.value')
            ->get();
        if ($custom_fields->isNotEmpty()) {
            $custom_fields_context .= "TICKET CUSTOM FIELDS:\n";
            foreach ($custom_fields as $cf) {
                if (!empty($cf->value)) {
                    $custom_fields_context .= "- {$cf->fieldname}: {$cf->value}\n";
                }
            }
        }
    } catch (\Exception $e) {}

    // Admin Notes
    $admin_notes_context = "";
    if (!empty($config['include_admin_notes'])) {
        try {
            $notes = Capsule::table('tblticketnotes')
                ->where('ticketid', $ticket_id)
                ->orderBy('date', 'desc')
                ->get();
            if ($notes->isNotEmpty()) {
                $admin_notes_context .= "PRIVATE ADMIN NOTES (Use as internal context only):\n";
                foreach ($notes as $n) {
                    $admin_notes_context .= "- [{$n->date}] {$n->admin}: " . trim(strip_tags($n->message)) . "\n";
                }
            }
        } catch (\Exception $e) {}
    }

    // Training Context - Use training cache if available, fallback to live query
    $training_context = "";
    if (!empty($config['enable_ticket_training'])) {
        // Try cached training data first
        try {
            $cached_training = Capsule::table('mod_gemini_training_cache')
                ->where('department_id', $ticket_data->did)
                ->orderBy('relevance_score', 'desc')
                ->limit(10)
                ->get();

            if ($cached_training->isNotEmpty()) {
                foreach ($cached_training as $t) {
                    $training_context .= "### Past Ticket (Category: {$t->category}):\n";
                    $training_context .= "Customer: " . substr($t->question_summary, 0, 200) . "\n";
                    $training_context .= "Staff Reply: " . substr($t->answer_summary, 0, 300) . "\n\n";
                }
            } else {
                // Fallback to live query
                $training_limit = min((int) ($config['training_tickets_limit'] ?? 5), 10);
                $training_context = get_recent_ticket_examples($ticket_id, $training_limit);
            }
        } catch (\Exception $e) {
            $training_limit = min((int) ($config['training_tickets_limit'] ?? 5), 10);
            $training_context = get_recent_ticket_examples($ticket_id, $training_limit);
        }
    } else {
        $training_limit = min((int) ($config['training_tickets_limit'] ?? 5), 5);
        $training_context = get_recent_ticket_examples($ticket_id, $training_limit);
    }

    // Define Task based on Mode
    $task_description = "";
    $content_to_process = "";
    switch ($mode) {
        case 'rewrite':
            $task_description = "TASK: Rewrite the following staff draft to be more professional, helpful, and empathetic. Keep the same core meaning but improve the tone and clarity.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'rewrite_longer':
            $task_description = "TASK: Expand the following staff draft. Add more detail, explanations, and a warmer tone while maintaining the original solution.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'rewrite_shorter':
            $task_description = "TASK: Shorten the following staff draft. Make it concise and direct while remaining polite and professional.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'rewrite_formal':
            $task_description = "TASK: Rewrite the following staff draft to be highly formal and professional. Use sophisticated language suitable for corporate communications.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'rewrite_friendly':
            $task_description = "TASK: Rewrite the following staff draft to be warm, friendly, and conversational. Use a helpful and welcoming tone.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'rewrite_empathetic':
            $task_description = "TASK: Rewrite the following staff draft to show deep empathy and understanding. Acknowledge the customer's feelings and frustration sincerely.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'rewrite_firm':
            $task_description = "TASK: Rewrite the following staff draft to be firm, direct, and assertive. Maintain professional boundaries while being very clear about policies or requirements.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'summarize':
            $task_description = "TASK: Provide a concise bullet-pointed summary of the ticket history and the current issue based on the messages below.";
            $content_to_process = "TICKET MESSAGES:\n{$message}";
            break;
        case 'triage':
            $task_description = "TASK: Provide a step-by-step troubleshooting checklist and escalation criteria based on the ticket history for the support agent.";
            $content_to_process = "TICKET MESSAGES:\n{$message}";
            break;
        case 'generate_kb':
            $task_description = "TASK: Create a draft Knowledge Base article based on the resolution provided in the ticket history below. Include a Title, Problem, and Solution section.";
            $content_to_process = "TICKET MESSAGES:\n{$message}";
            break;
        case 'internal_handover':
            $task_description = "TASK: Write a concise internal shift-handover note for another support engineer summarizing: (1) Issue, (2) What was checked/tried so far, (3) Current status & blockers, (4) Exact recommended next step.";
            $content_to_process = "TICKET MESSAGES:\n{$message}";
            break;
        case 'rewrite_fix_grammar':
            $task_description = "TASK: Fix the spelling and grammar in the following staff draft without significantly changing its tone or length.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'rewrite_professional':
            $task_description = "TASK: Rewrite the following staff draft to sound highly professional and polished.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'rewrite_technical':
            $task_description = "TASK: Rewrite the following staff draft to be detailed, technical, and precise.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'rewrite_translate':
            $task_description = "TASK: Translate the following staff draft as instructed. Retain the professional formatting.";
            $content_to_process = "STAFF DRAFT:\n{$current_draft}";
            break;
        case 'generate':
        default:
            $task_description = "TASK: Generate a professional support reply to the customer's message below using the provided context, knowledge base, customer profile, and past successful examples.";
            $content_to_process = "CUSTOMER MESSAGE:\n{$message}";
            break;
    }

    // Language Constraint
    $language_instruction = "";
    if ($lang === 'bangla') {
        $language_instruction = "IMPORTANT: You MUST respond in Bangla (Bengali) language only.";
    } elseif ($lang === 'english') {
        $language_instruction = "IMPORTANT: You MUST respond in English language only.";
    } else {
        $language_instruction = "IMPORTANT: Detect the language of the customer's message and respond in that same language.";
    }

    if ($role === 'client') {
        $task_description = "";
        $content_to_process = "";
        switch ($mode) {
            case 'rewrite':
                $task_description = "TASK: Rewrite your draft below to be clearer and more effective.";
                $content_to_process = "YOUR DRAFT:\n{$current_draft}";
                break;
            case 'rewrite_longer':
                $task_description = "TASK: Expand your draft below with more details about your issue.";
                $content_to_process = "YOUR DRAFT:\n{$current_draft}";
                break;
            case 'rewrite_shorter':
                $task_description = "TASK: Shorten your draft below to be concise and direct.";
                $content_to_process = "YOUR DRAFT:\n{$current_draft}";
                break;
            case 'rewrite_formal':
                $task_description = "TASK: Rewrite your draft below to be formal and polite.";
                $content_to_process = "YOUR DRAFT:\n{$current_draft}";
                break;
            case 'rewrite_friendly':
                $task_description = "TASK: Rewrite your draft below to be friendly and conversational.";
                $content_to_process = "YOUR DRAFT:\n{$current_draft}";
                break;
            case 'rewrite_empathetic':
                $task_description = "TASK: Rewrite your draft to show understanding and patience.";
                $content_to_process = "YOUR DRAFT:\n{$current_draft}";
                break;
            case 'rewrite_firm':
                $task_description = "TASK: Rewrite your draft to be firm, expressing urgency or dissatisfaction clearly but respectfully.";
                $content_to_process = "YOUR DRAFT:\n{$current_draft}";
                break;
            case 'summarize':
                $task_description = "TASK: Summarize your current problem and what support has asked/done so far based on the ticket history.";
                $content_to_process = "TICKET MESSAGES:\n{$message}";
                break;
            case 'format_logs':
                $task_description = "TASK: Format the raw error message/logs in your draft cleanly using Markdown code blocks, and add a 1-2 sentence plain-language summary of what you are seeing so the support team can diagnose it quickly.";
                $content_to_process = "YOUR RAW ERROR / LOGS:\n{$current_draft}";
                break;
            case 'rewrite_fix_grammar':
                $task_description = "TASK: Fix spelling and grammar in your draft while keeping the exact meaning.";
                $content_to_process = "YOUR DRAFT:\n{$current_draft}";
                break;
            case 'generate':
            default:
                $task_description = "TASK: Write a reply to the support agent based on the ticket history below.";
                $content_to_process = "TICKET HISTORY:\n{$message}";
                break;
        }

        $prompt = "
You are a customer of a web hosting company writing a reply to a support agent.
{$task_description}
{$language_instruction}

{$content_to_process}

GUIDELINES:
1. Write from the perspective of the customer (use 'I', 'we', 'my account').
2. Do not act as a support agent.
3. Be clear and state your issue or request accurately.
4. Output ONLY the raw response text without quotes or conversational filler.
";
        return $prompt;
    }

    // Build enhanced AI prompt with customer context
    $prompt = "
You are an expert AI Support Assistant for a hosting company.
{$task_description}
{$language_instruction}

TICKET CONTEXT:
- Customer: {$customer_name}
- Subject: {$ticket_subject}
- Department: {$department_name}
- Detected Mood: {$analysis['mood']}
";

    // Add custom fields
    if (!empty($custom_fields_context)) {
        $prompt .= "\n{$custom_fields_context}\n";
    }

    // Add customer profile if available
    if (!empty($customer_context)) {
        $prompt .= "\n{$customer_context}\n";
    }

    // Add admin notes if available
    if (!empty($admin_notes_context)) {
        $prompt .= "\n{$admin_notes_context}\n";
    }

    $prompt .= "
KNOWLEDGE BASE:
{$qa_context}

HISTORICAL TRAINING EXAMPLES (Follow this style):
{$training_context}

{$content_to_process}

GUIDELINES:
1. Tone should be " . get_tone_guidance($analysis['mood']) . ".
2. Address the customer by name.
3. Be clear, professional, and helpful.
4. If customer profile data is available, use it to personalize your response (e.g., acknowledge their loyalty, reference their products).
5. If the customer has spent significant money or has many products, prioritize their satisfaction.
6. Output ONLY the response text.

RESPONSE:";

    // Append any global custom instructions
    if (!empty($config['custom_instructions'])) {
        $prompt .= "\nGLOBAL CUSTOM INSTRUCTIONS:\n" . $config['custom_instructions'] . "\n";
    }

    // Append specific custom instructions per request if present
    if (!empty($_POST['custom_prompt'])) {
        $prompt .= "\nSPECIFIC INSTRUCTIONS FOR THIS REPLY:\n" . strip_tags($_POST['custom_prompt']) . "\n";
    }

    return $prompt;
}
function get_recent_ticket_examples($ticket_id, $limit = 5)
{
    try {
        $current_ticket = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
        if (!$current_ticket)
            return "";

        // Fetch recent tickets from same department that have staff replies
        $examples = Capsule::table('tbltickets')
            ->join('tblticketreplies', 'tbltickets.id', '=', 'tblticketreplies.tid')
            ->where('tbltickets.did', $current_ticket->did)
            ->where('tbltickets.id', '!=', $ticket_id)
            ->where('tblticketreplies.admin', '!=', '')
            ->select('tbltickets.title', 'tbltickets.message as question', 'tblticketreplies.message as answer')
            ->orderBy('tbltickets.id', 'desc')
            ->limit($limit)
            ->get();

        if ($examples->isEmpty()) {
            return "No recent examples found for this department.";
        }

        $context = "";
        foreach ($examples as $ex) {
            $context .= "### Past Ticket: {$ex->title}\n";
            $context .= "Customer: " . substr(strip_tags($ex->question), 0, 150) . "...\n";
            $context .= "Staff Reply: " . substr(strip_tags($ex->answer), 0, 250) . "...\n\n";
        }
        return $context;
    } catch (Exception $e) {
        return "";
    }
}



function get_tone_guidance($mood)
{
    $guidance = [
        'angry' => 'Be very apologetic, empathetic, and focus on immediate resolution. Acknowledge their frustration.',
        'frustrated' => 'Show understanding and patience. Provide clear, step-by-step solutions.',
        'urgent' => 'Be direct and efficient. Prioritize quick resolution and clear action steps.',
        'satisfied' => 'Be warm and appreciative. Maintain positive engagement.',
        'calm' => 'Be professional and helpful. Provide detailed, clear information.',
        'neutral' => 'Be professional, clear, and helpful. Standard support tone.'
    ];

    return $guidance[$mood] ?? $guidance['neutral'];
}

function call_gemini_api($prompt, $config, $context = 'ticket_reply', $ticket_id = null)
{
    $api_key = $config['gemini_api_key'] ?? '';
    $model = $config['gemini_model'] ?? 'gemini-2.5-flash';

    $custom_instruction = trim((string) ($config['custom_instructions'] ?? ''));
    $system_instruction = "You are an AI support assistant. ";
    if ($custom_instruction !== '') {
        $system_instruction .= "Follow these specific instructions: " . $custom_instruction . "\n\n";
    } else {
        $system_instruction .= "Your job is to generate helpful, clear, and professional replies.\n\n";
    }

    $system_instruction .= "CRITICAL: Return ONLY valid JSON in this exact format:
{
  \"reply\": \"Your helpful message\",
  \"confidence\": 0.85
}";

    $payload = [
        'systemInstruction' => [
            'parts' => [['text' => $system_instruction]]
        ],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature' => (float) ($config['temperature'] ?? 0.4),
            'maxOutputTokens' => max(800, (int) ($config['max_tokens'] ?? 2048)),
            'responseMimeType' => 'application/json',
        ]
    ];

    $res = neko_gemini_http_post($model, $api_key, $payload, $ticket_id, $config['gemini_fallback_model'] ?? '');
    if (empty($res['success'])) {
        return [
            'success' => false,
            'error' => 'Gemini API failed: ' . ($res['error'] ?? 'Unknown error'),
            'model_used' => $model,
            'http_code' => $res['http_code'] ?? 0,
            'response_data' => $res['response_data'] ?? null,
        ];
    }

    $raw_text = trim((string) ($res['data']['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    $parsed = neko_parse_agent_json($raw_text);
    if ($parsed !== null) {
        $ai_text = trim((string) $parsed['reply']);
        $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : null;
    } else {
        $ai_text = $raw_text;
        $confidence = 0.6;
    }

    return [
        'success' => true,
        'response' => $ai_text,
        'confidence' => $confidence,
        'model_used' => $res['model_used'] ?? $model,
        'http_code' => $res['http_code'] ?? 200,
        'attempts' => $res['attempts'] ?? 1,
        'full_response' => $res['data'],
    ];
}







function log_api_call($endpoint, $request_data, $response_data, $http_code, $curl_error, $response_time, $ticket_id = null, $status = 'success', $error_details = null, $model_used = null, $tokens_used = 0)
{
    $config = get_gemini_config();

    if (!$config['enable_debug_logging'] && $status == 'success') {
        return; // Only log errors if debug logging is disabled
    }

    try {
        neko_ensure_agent_tables();
        $safe_endpoint = function_exists('neko_redact_secrets') ? neko_redact_secrets((string) $endpoint) : (string) $endpoint;
        $req_str = is_array($request_data) ? json_encode($request_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $request_data;
        $res_str = is_array($response_data) ? json_encode($response_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $response_data;
        if (function_exists('neko_redact_secrets')) {
            $req_str = neko_redact_secrets($req_str);
            $res_str = neko_redact_secrets($res_str);
        }

        $row = [
            'api_endpoint' => substr($safe_endpoint, 0, 255),
            'request_data' => $req_str,
            'response_data' => $res_str,
            'http_code' => (int) $http_code,
            'curl_error' => $curl_error,
            'response_time' => $response_time,
            'ticket_id' => $ticket_id,
            'status' => $status,
            'error_details' => $error_details ? (function_exists('neko_redact_secrets') ? neko_redact_secrets((string) $error_details) : (string) $error_details) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if (Capsule::schema()->hasColumn('mod_gemini_api_logs', 'model_used')) {
            $row['model_used'] = $model_used ? substr((string) $model_used, 0, 80) : null;
        }
        if (Capsule::schema()->hasColumn('mod_gemini_api_logs', 'tokens_used')) {
            $row['tokens_used'] = (int) $tokens_used;
        }

        Capsule::table('mod_gemini_api_logs')->insert($row);
    } catch (Exception $e) {
        logActivity("Gemini AI Assistant: Failed to log API call - " . $e->getMessage());
    }
}

function add_ticket_reply($ticket_id, $response, $analysis)
{
    // Load addon configuration
    $config = get_gemini_config();

    // Assistant name (fallback)
    $assistant_name = !empty($config['assistant_name']) ? $config['assistant_name'] : 'AI Support';

    // Normalize $response
    $reply_text = is_array($response) && isset($response['response'])
        ? $response['response']
        : (string) $response;

    $confidence_value = null;

    // ✅ Always prefer the real confidence from Gemini API response
    if (is_array($response) && isset($response['confidence']) && $response['confidence'] !== null) {
        $confidence_value = (float) $response['confidence'];
    } elseif (isset($response['raw_confidence']) && $response['raw_confidence'] !== null) {
        // Optional: in case you added 'raw_confidence' earlier in generate_ai_ticket_response()
        $confidence_value = (float) $response['raw_confidence'];
    } elseif (isset($analysis['confidence'])) {
        // Fallback only if API didn’t return confidence
        $confidence_value = (float) $analysis['confidence'];
    } else {
        // Default safety baseline
        $confidence_value = 0.5;
    }


    // --- Build Footer Based on Settings ---
    $footer = '';

    if (!empty($config['ai_footer_enabled'])) { // boolean true from get_gemini_config()
        // Custom footer text (allowing \n line breaks)
        $footer_text = str_replace('\n', "\n", $config['ai_footer_text'] ?? "---\n**This response was generated by AI Assistant**");

        // ✅ Add confidence level if enabled and available
        if (!empty($config['ai_confidence_enabled']) && $confidence_value !== null) {
            $conf_percent = round($confidence_value * 100, 1);
            $footer_text .= " (Confidence: {$conf_percent}%)";
        }

        $footer = "\n\n" . trim($footer_text);
    }

    // Final message for WHMCS
    $final_message = trim($reply_text . $footer);

    $admin = Capsule::table('tbladmins')->where('disabled', 0)->first();
    $api_success = false;

    if ($admin) {
        $apiResult = localAPI('AddTicketReply', [
            'ticketid' => $ticket_id,
            'message' => $final_message,
            'adminusername' => $admin->username,
            'markdown' => true,
        ]);

        if ($apiResult['result'] == 'success') {
            $api_success = true;
            Capsule::table('tblticketreplies')
                ->where('tid', $ticket_id)
                ->where('admin', $admin->username)
                ->orderBy('id', 'desc')
                ->limit(1)
                ->update(['admin' => $assistant_name]);
                
            $target_status = !empty($config['autopilot_status']) ? $config['autopilot_status'] : 'Open';
            Capsule::table('tbltickets')->where('id', $ticket_id)->update(['status' => $target_status]);
        }
    }

    if (!$api_success) {
        // --- Insert the reply into WHMCS database ---
        Capsule::table('tblticketreplies')->insert([
            'tid' => $ticket_id,
            'userid' => 0, // AI/system user
            'contactid' => 0,
            'admin' => $assistant_name,
            'message' => $final_message,
            'date' => date('Y-m-d H:i:s'),
            'editor' => 'markdown',
        ]);

        // --- Update ticket last reply timestamp and status ---
        $target_status = !empty($config['autopilot_status']) ? $config['autopilot_status'] : 'Open';
        $update_data = [
            'lastreply' => date('Y-m-d H:i:s'),
            'status' => $target_status
        ];

        Capsule::table('tbltickets')
            ->where('id', $ticket_id)
            ->update($update_data);
    }

    // Log activity (for debugging)
    logActivity("AI Assistant: Added reply to ticket #$ticket_id (confidence: " . ($confidence_value ?? 'N/A') . ")");
}




function mark_ticket_for_human($ticket_id)
{
    $config = get_gemini_config();

    // Get client ID from the ticket
    $ticket = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
    $client_id = $ticket->userid ?? 0;

    // Use the full escalation system
    escalate_to_admin($ticket_id, $client_id, 'Customer requested human assistance or high escalation detected');

    // Notify the client that admin will connect
    notify_client_escalation($ticket_id, $config);
}

/**
 * Neko AI Assistant — Modern Animated Widget (12-Hour Time + Glow Progress)
 */
add_hook('AdminHomeWidgets', 1, function () {
    return new NekoAIAssistantModernWidget();
});

class NekoAIAssistantModernWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = '🤖 Neko AI Assistant';
    protected $description = 'AI insights, confidence levels, and escalation overview.';
    protected $weight = 120;
    protected $columns = 1;
    protected $cache = false;
    protected $cacheExpiry = 60;

    public function getData()
    {
        $today = date('Y-m-d');
        try {
            $todayReplies = \WHMCS\Database\Capsule::table('mod_gemini_ai_logs')
                ->whereDate('created_at', $today)
                ->count();

            $avgConfidence = \WHMCS\Database\Capsule::table('mod_gemini_ai_logs')
                ->whereDate('created_at', $today)
                ->avg('confidence_score');

            $escalatedTickets = \WHMCS\Database\Capsule::table('mod_gemini_ticket_analysis')
                ->where('needs_human', 1)
                ->count();

            return [
                'todayReplies' => $todayReplies,
                'avgConfidence' => round($avgConfidence * 100, 1),
                'escalatedTickets' => $escalatedTickets,
            ];
        } catch (\Exception $e) {
            return [
                'todayReplies' => 0,
                'avgConfidence' => 0,
                'escalatedTickets' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    public function generateOutput($data)
    {
        $error = $data['error'] ?? '';
        if ($error) {
            return "<div class='widget-content-padded'><strong>Error:</strong> {$error}</div>";
        }

        $confidence = $data['avgConfidence'];
        $updatedTime = date('h:i A'); // ✅ 12-hour format with AM/PM
        $confColor = $confidence >= 80 ? '#4ade80' : ($confidence >= 60 ? '#facc15' : '#f87171');
        $glow = $confidence >= 80 ? 'glow-bar' : ''; // enable glow if high confidence

        return <<<HTML
<style>
.neko-widget {
    background: linear-gradient(145deg, #ffffff, #f3f4f6);
    border: 1px solid rgba(0,0,0,0.05);
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(0,0,0,0.06);
    padding: 18px 20px;
    font-size: 14px;
    transition: all 0.3s ease;
}
.neko-widget:hover {
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
    transform: translateY(-2px);
}
.neko-widget .stat {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
    font-weight: 500;
    color: #1f2937;
}
.neko-widget .progress {
    height: 10px;
    background: #e5e7eb;
    border-radius: 50px;
    overflow: hidden;
    margin: 8px 0 12px 0;
}
.neko-widget .progress-bar {
    height: 100%;
    border-radius: 50px;
    transition: width 1s ease;
    background: linear-gradient(90deg, {$confColor}, #3b82f6);
}
.glow-bar {
    animation: glowPulse 2.5s infinite ease-in-out;
    box-shadow: 0 0 8px 2px rgba(76,175,80,0.3);
}
@keyframes glowPulse {
    0% { box-shadow: 0 0 8px 2px rgba(76,175,80,0.3); }
    50% { box-shadow: 0 0 15px 5px rgba(76,175,80,0.6); }
    100% { box-shadow: 0 0 8px 2px rgba(76,175,80,0.3); }
}
.neko-widget .footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #6b7280;
    font-size: 12px;
}
.neko-widget .badge {
    background: #3b82f6;
    color: white;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 11px;
}
</style>

<div class="neko-widget">
    <div class="stat">
        <span>📩 Replies Today</span>
        <strong>{$data['todayReplies']}</strong>
    </div>

    <div class="stat">
        <span>🎯 Avg Confidence</span>
        <strong>{$confidence}%</strong>
    </div>

    <div class="progress">
        <div class="progress-bar {$glow}" style="width: {$confidence}%;"></div>
    </div>

    <div class="stat">
        <span>⚠️ Escalations</span>
        <strong>{$data['escalatedTickets']}</strong>
    </div>

    <div class="footer">
        <span><i class="fas fa-clock"></i> Updated: {$updatedTime}</span>
        <span class="badge">Neko AI Active</span>
    </div>
</div>
HTML;
}
}

/**
 * Search WHMCS Knowledge Base based on message keywords
 */
function search_whmcs_kb($message, $limit = 3)
{
    try {
        // Extract basic keywords (words > 4 chars)
        $words = str_word_count(strtolower(strip_tags($message)), 1);
        $keywords = [];
        $stop_words = ['please', 'thanks', 'hello', 'there', 'would', 'could', 'should', 'about', 'which', 'issue', 'error', 'problem', 'help'];
        foreach ($words as $word) {
            if (strlen($word) > 4 && !in_array($word, $stop_words)) {
                $keywords[] = $word;
            }
        }

        if (empty($keywords)) {
            return collect([]);
        }

        // Top 5 keywords
        $keywords = array_slice(array_unique($keywords), 0, 5);

        $query = Capsule::table('tblknowledgebase');
        
        $query->where(function($q) use ($keywords) {
            foreach ($keywords as $kw) {
                $q->orWhere('title', 'LIKE', '%' . $kw . '%')
                  ->orWhere('article', 'LIKE', '%' . $kw . '%');
            }
        });

        return $query->orderBy('views', 'desc')->limit($limit)->get();
    } catch (\Exception $e) {
        logActivity("Neko AI KB Search Error: " . $e->getMessage());
        return collect([]);
    }
}

add_hook('AfterCronJob', 1, function ($vars) {
    $config = get_gemini_config();
    if (empty($config['auto_reply_enabled'])) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $pending_replies = Capsule::table('mod_gemini_reply_queue')
        ->where('status', 'pending')
        ->where('scheduled_at', '<=', $now)
        ->limit(10)
        ->get();

    foreach ($pending_replies as $reply) {
        Capsule::table('mod_gemini_reply_queue')->where('id', $reply->id)->update(['status' => 'processing']);

        $ticket = Capsule::table('tbltickets')->where('id', $reply->ticket_id)->first();
        if (!$ticket || strcasecmp((string) $ticket->status, 'Closed') === 0) {
            Capsule::table('mod_gemini_reply_queue')->where('id', $reply->id)->update(['status' => 'cancelled']);
            continue;
        }

        // Skip if ticket was escalated to human or if staff already replied while queued
        $analysis_row = Capsule::table('mod_gemini_ticket_analysis')->where('ticket_id', $reply->ticket_id)->first();
        $staff_replied = Capsule::table('tblticketreplies')
            ->where('tid', $reply->ticket_id)
            ->where('admin', '!=', '')
            ->where('date', '>', $reply->created_at)
            ->exists();

        if (($analysis_row && !empty($analysis_row->needs_human)) || $staff_replied) {
            Capsule::table('mod_gemini_reply_queue')->where('id', $reply->id)->update(['status' => 'cancelled']);
            logActivity("Neko AI: Cancelled queued reply for ticket #{$reply->ticket_id} (already escalated or staff replied)");
            continue;
        }

        $analysis = analyze_ticket_content($reply->user_message, $reply->ticket_id);
        $solved = neko_execute_autopilot_solve($reply->ticket_id, $reply->user_message, $analysis, $config);

        Capsule::table('mod_gemini_reply_queue')->where('id', $reply->id)->update([
            'status' => $solved ? 'completed' : 'held_for_human'
        ]);
    }
});