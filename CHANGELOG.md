# Changelog

All notable changes to **Neko AI Assistant for WHMCS** are documented in this file.

## [4.0.0] - 2026-11-18

### Autonomous Problem-Solving AI Agent
- **Gemini `v1beta` Function-Calling Agent Loop** (`includes/agent_runner.php`, `includes/agent_tools.php`): Replaced single-shot generic text generation with a multi-turn reasoning & diagnostic agent (`neko_run_whmcs_agent`) that verifies real WHMCS account state before replying.
- **Read-Only Diagnostic Tools (Client-Scoped)**:
  - `get_ticket_thread`: Full chronological ticket history, custom fields, and admin notes.
  - `get_client_overview`, `get_client_services` (all statuses including `Suspended` + `suspendreason`, `Pending`, `Terminated`, server IP/nameservers), `get_client_invoices` (Unpaid/Overdue/Paid + line items), `get_client_orders`, `get_client_domains`, and `get_domain_registrar_info`.
  - `get_module_and_activity_errors` (`tblmodulelog`, `tblactivitylog`, `GetModuleQueue`) and `get_client_emails`.
  - `check_domain_dns` (live `A`, `AAAA`, `NS`, `MX`, `TXT`, `CNAME` lookup compared against WHMCS server IP) and `check_website_http_ssl` (HTTP/HTTPS probe + TLS certificate expiry/CN check with SSRF IP protection).
  - `check_server_account_status`: Read-only live cPanel/WHM and DirectAdmin account inspection via `tblservers`.
  - `search_knowledgebase` & `search_similar_tickets`: On-demand keyword-filtered lookup.
- **Safe & Guarded Action Tools**:
  - Safe self-service actions (`agent_allow_safe_actions`): `resend_client_email`, `apply_credit_to_invoice`, `update_domain_nameservers`, `update_domain_lock`, `request_domain_epp`, `update_ticket_metadata`.
  - Guarded service/order actions (`agent_allow_service_actions`, opt-in): `unsuspend_paid_service` (only when linked invoices are paid and not suspended for abuse/fraud/TOS), `provision_paid_pending_service`, `accept_paid_order`, `run_vps_power_action`.
  - `escalate_to_human`: Escalates with a structured diagnostic admin note.
- **Uncertain-Reply Protection (`agent_uncertain_behavior`)**:
  - Defaults to `draft_note`—when confidence is below threshold or human intervention is needed, saves a private Admin Note with all diagnostic findings and a suggested draft instead of sending an annoying/uncertain reply to the customer.
- **Agent Audit Log & Upgrade Hook**: Added `mod_gemini_agent_actions` database table, `gemini_ai_assistant_upgrade()`, and an **Agent Actions** audit tab in the admin dashboard.

### Client Area UI & Features (`submitticket` + `viewticket`)
- **Ticket Submit Assistant (`submitticket`)**:
  - **Instant Service & DNS Check (Solve Now)** (`client_submit_diagnose`): Strictly client-scoped self-service troubleshooter checking service status, unpaid invoices, live DNS/HTTP/SSL, and Knowledge Base articles before submitting a ticket.
  - **Quick Issue Templates**: One-click structured chips for *Website Down / 5xx*, *Email Issue*, *SSL / HTTPS*, *DNS / Nameservers*, and *Billing / Invoice*.
  - **Smart Ticket Writer** (`client_submit_assist`): *Polish My Description*, *Structure Issue Details*, *Suggest Subject*, and *Format Error Logs* (Auto / English / Bangla).
- **Ticket Reply Copilot (`viewticket`)**:
  - Modern theme-independent card UI compatible with Bootstrap 3, 4, 5, and Markdown editors.
  - Added *Run Live Service / DNS Check* (`client_viewticket_diagnose`), non-destructive *Summarize Thread* panel, *Format Error Logs*, *Live Agent* escalation button, and *Rewrite / Polish* menu.

### Admin Ticket Copilot & Dashboard UI
- **Admin Ticket Support Copilot** (`supporttickets.php`):
  - **Live Account Snapshot Strip** (`neko_ticket_copilot_snapshot`): Displays mood, confidence, unpaid invoices, credit balance, service status pills, and clickable domain chips for instant zero-token DNS & SSL checks (`neko_quick_dns_ssl_check`).
  - **Dedicated Staff Output Panel**: Keeps *Summarize*, *Root-Cause Triage*, *Handover Note* (`internal_handover`), *Draft KB*, and live DNS/SSL reports separate from the public reply textarea, with *Copy*, *Save Private Note* (`neko_save_admin_note`), and *Put in Reply Box* actions.
  - **1-Click Reset AI Escalation** (`neko_reset_ticket_escalation`) to clear `needs_human` directly from the ticket view.
- **Addon Dashboard Upgrades**:
  - Added **Agent Diagnostic Bench** (`#agent-bench` / `admin_diag_bench`) for safe read-only dry-runs on any Ticket ID or Domain.
  - Fixed Quick Actions tab navigation and scoped CSS cleanly to `.neko-ai-container`.

### Bug Fixes
- Fixed `get_gemini_config()` missing `autopilot_departments`, `autopilot_delay`, `autopilot_status`, and `include_admin_notes`.
- Fixed `TicketOpen`, `TicketUserReply`, and `AfterCronJob` to enforce `needs_human` / `Closed` / staff-replied guards and full conversation thread context.
- Expanded `detect_mood()` with English, Bangla, and repeat-frustration detection plus safe `mbstring` fallback.
- Fixed JavaScript syntax errors caused by PHP `<<<HTML` heredoc newline expansion in `AdminAreaFooterOutput` and `ClientAreaFooterOutput` by switching to Nowdoc (`<<<'HTML'`) and anchoring above `.md-editor`.

---

## [3.0.0]
- Initial public release with Google Gemini auto-reply, mood detection, customer insights, ticket training, WHMCS KB search, live agent escalation, analytics dashboard, and multi-language (English/Bangla) support.
