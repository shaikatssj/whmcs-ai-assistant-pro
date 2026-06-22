<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// Hook for ticket creation and replies
add_hook('TicketOpen', 1, function ($vars) {
    $config = get_gemini_config();

    if (!$config['auto_reply_enabled']) {
        return;
    }
    $ticket_id = $vars['ticketid'];

    $ticket_data = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
    if (!$ticket_data) return;

    // Try to get the latest message from replies
    $message = Capsule::table('tblticketreplies')
        ->where('tid', $ticket_id)
        ->orderBy('id', 'desc')
        ->value('message');

    // If no replies exist yet, use the original ticket message
    if (empty($message)) {
        $message = Capsule::table('tbltickets')
            ->where('id', $ticket_id)
            ->value('message');
    }

    // Check if user requested to talk to agent FIRST (bypass cooldown)
    if (should_skip_ai_reply($message)) {
        mark_ticket_for_human($ticket_id);
        logActivity("AI Assistant: Skipped auto-reply for ticket #$ticket_id - user requested human agent");
        return;
    }

    // 🛑 Cooldown check: Don't auto-reply if we sent one in the last 2 minutes for this ticket
    $recent_reply = Capsule::table('mod_gemini_ai_logs')
        ->where('ticket_id', $ticket_id)
        ->where('response_type', 'generate')
        ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-2 minutes')))
        ->first();

    if ($recent_reply) {
        logActivity("Gemini AI: Skipping auto-reply for ticket #$ticket_id - cooldown active");
        return;
    }

    // Log for debugging
    logActivity("Gemini AI: fetched message for ticket #$ticket_id (" . strlen($message) . " chars)");

    // Analyze ticket mood and content
    $analysis = analyze_ticket_content($message, $ticket_id);

    // Check confidence threshold
    if ($analysis['confidence'] < $config['confidence_threshold']) {
        logActivity("AI Assistant: Low confidence ({$analysis['confidence']}) for ticket #$ticket_id - skipping auto-reply");
        return;
    }

    // Check Autopilot Departments
    $autopilot_departments = array_filter(array_map('trim', explode(',', $config['autopilot_departments'] ?? '')));
    if (!empty($autopilot_departments) && !in_array($ticket_data->did, $autopilot_departments)) {
        logActivity("Gemini AI: Skipping auto-reply for ticket #$ticket_id - department not enabled for autopilot");
        return;
    }

    // Autopilot Delay Check
    $delay = (int) ($config['autopilot_delay'] ?? 0);
    if ($delay > 0) {
        // Remove any existing pending queue for this ticket to avoid duplicates
        Capsule::table('mod_gemini_reply_queue')->where('ticket_id', $ticket_id)->where('status', 'pending')->delete();
        
        Capsule::table('mod_gemini_reply_queue')->insert([
            'ticket_id' => $ticket_id,
            'user_message' => $message,
            'status' => 'pending',
            'scheduled_at' => date('Y-m-d H:i:s', strtotime("+$delay minutes")),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        logActivity("Gemini AI: Queued autopilot reply for ticket #$ticket_id (Delay: $delay mins)");
        return;
    }

    // Generate AI response immediately
    $ai_response = generate_ai_ticket_response($message, $ticket_id, $analysis);

    if ($ai_response && $ai_response['success']) {
        // Add the AI response to the ticket
        add_ticket_reply($ticket_id, $ai_response, $analysis);


        logActivity("AI Assistant: Auto-reply sent for ticket #$ticket_id with confidence: {$ai_response['confidence']}");
    } else {
        logActivity("AI Assistant: Failed to generate response for ticket #$ticket_id - " . ($ai_response['error'] ?? 'Unknown error'));
    }
});

// Hook for ticket replies from users
add_hook('TicketUserReply', 1, function ($vars) {
    $config = get_gemini_config();

    if (!$config['auto_reply_enabled']) {
        return;
    }

    $ticket_id = $vars['ticketid'];

    $ticket_data = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
    if (!$ticket_data) return;

    $message = $vars['message'];

    // Check if user requested to talk to agent FIRST (bypass cooldown)
    if (should_skip_ai_reply($message)) {
        mark_ticket_for_human($ticket_id);
        logActivity("AI Assistant: User requested human agent for ticket #$ticket_id");
        return;
    }

    // 🛑 Cooldown check: Don't auto-reply if we sent one in the last 2 minutes for this ticket
    $recent_reply = Capsule::table('mod_gemini_ai_logs')
        ->where('ticket_id', $ticket_id)
        ->where('response_type', 'generate')
        ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-2 minutes')))
        ->first();

    if ($recent_reply) {
        logActivity("Gemini AI: Skipping auto-reply for ticket #$ticket_id - cooldown active");
        return;
    }

    // Analyze the reply
    $analysis = analyze_ticket_content($message, $ticket_id);

    // Check if escalation is needed
    if ($analysis['escalation_level'] > 0.8) {
        mark_ticket_for_human($ticket_id);
        logActivity("AI Assistant: Ticket #$ticket_id escalated due to high mood escalation");
        return;
    }

    // Check Autopilot Departments
    $autopilot_departments = array_filter(array_map('trim', explode(',', $config['autopilot_departments'] ?? '')));
    if (!empty($autopilot_departments) && !in_array($ticket_data->did, $autopilot_departments)) {
        return;
    }

    // Generate response if confidence is high enough
    if ($analysis['confidence'] >= $config['confidence_threshold']) {
        
        // Autopilot Delay Check
        $delay = (int) ($config['autopilot_delay'] ?? 0);
        if ($delay > 0) {
            Capsule::table('mod_gemini_reply_queue')->where('ticket_id', $ticket_id)->where('status', 'pending')->delete();
            Capsule::table('mod_gemini_reply_queue')->insert([
                'ticket_id' => $ticket_id,
                'user_message' => $message,
                'status' => 'pending',
                'scheduled_at' => date('Y-m-d H:i:s', strtotime("+$delay minutes")),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            logActivity("Gemini AI: Queued autopilot reply for ticket #$ticket_id (Delay: $delay mins)");
            return;
        }

        $ai_response = generate_ai_ticket_response($message, $ticket_id, $analysis);

        if ($ai_response && $ai_response['success']) {
            add_ticket_reply($ticket_id, $ai_response, $analysis);
            logActivity("AI Assistant: Auto-reply sent for ticket #$ticket_id with confidence: " . ($ai_response['confidence'] ?? $analysis['confidence']));
        } else {
            logActivity("AI Assistant: Failed to generate response for ticket #$ticket_id - " . ($ai_response['error'] ?? 'Unknown error'));
        }
    } else {
        logActivity("AI Assistant: Low confidence ({$analysis['confidence']}) for ticket #$ticket_id - skipping auto-reply");
    }
});

// Hook to add AI reply button in admin area
add_hook('AdminAreaHeaderOutput', 1, function ($vars) {
    // 1. Handle AJAX requests for AI actions
    if ($vars['filename'] == 'supporttickets' && isset($_POST['action'])) {
        $action = $_POST['action'];
        $ticket_id = (int) $_POST['ticket_id'];

        if ($action == 'generate_ai_reply') {
            $mode = $_POST['reply_mode'] ?? 'generate';
            $lang = $_POST['reply_lang'] ?? 'auto';
            $current_message = $_POST['current_message'] ?? '';

            // Get ticket message(s) for context
            $message = Capsule::table('tbltickets')->where('id', $ticket_id)->value('message');

            $analysis = analyze_ticket_content($message, $ticket_id);
            $ai_response = generate_ai_ticket_response($message, $ticket_id, $analysis, $mode, $current_message, $lang);

            header('Content-Type: application/json');
            echo json_encode($ai_response);
            exit;
        }

        if ($action == 'analyze_ticket_mood') {
            $message = Capsule::table('tbltickets')->where('id', $ticket_id)->value('message');
            $analysis = analyze_ticket_content($message, $ticket_id);

            header('Content-Type: application/json');
            echo json_encode(array_merge(['success' => true], $analysis));
            exit;
        }
    }
});

// Hook to inject AI reply UI in admin area
add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    if ($vars['filename'] == 'supporttickets' && isset($_GET['id'])) {
        $ticket_id = (int) $_GET['id'];

        return <<<HTML
<script>
$(document).ready(function() {
    if ($('.ai-reply-container').length > 0) return; // Prevent duplicate injection
    
    console.log("Gemini AI: Module script loaded. Checking for reply area...");
    
    var target = $('#replymessage');
    if (target.length === 0) {
        target = $('#content'); // Fallback
    }
    
    if (target.length === 0) {
        console.warn("Gemini AI: Could not find #replymessage or #content. Checking for any textarea...");
        target = $('textarea[name="replymessage"]');
    }
    
    if (target.length === 0) {
        console.error("Gemini AI: Target area not found. Dropdown will not be injected.");
        return;
    }

    console.log("Gemini AI: Target found, injecting dropdown.");
    
    // Add AI reply dropdown to the ticket reply area with sub-menus for a cleaner UI
    var aiDropdown = `
        <div class="ai-reply-container" style="margin: 10px 0; display: inline-block;">
            <div class="btn-group dropdown">
                <button type="button" id="aiReplyBtn" class="btn btn-sm btn-info dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="background-color: #31a7c5; border-color: #269abc; color: white; font-weight: bold;">
                    <i class="fas fa-robot"></i> <span class="btn-text">🤖 AI Reply</span> <span class="caret"></span>
                </button>
                <ul class="dropdown-menu multi-level" role="menu" aria-labelledby="dropdownMenu">
                    <li><a href="javascript:void(0)" onclick="generateAIResponse('generate')"><i class="fas fa-magic"></i> Generate Reply</a></li>
                    
                    <li class="dropdown-divider"></li>
                    <li class="dropdown-submenu">
                        <a tabindex="-1" href="javascript:void(0)"><i class="fas fa-pen-nib"></i> Rewrite Tone</a>
                        <ul class="dropdown-menu">
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_formal')">Formal</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_professional')">Professional</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_technical')">Technical</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_friendly')">Friendly</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_empathetic')">Empathetic</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_firm')">Firm/Direct</a></li>
                        </ul>
                    </li>

                    <li class="dropdown-submenu">
                        <a tabindex="-1" href="javascript:void(0)"><i class="fas fa-arrows-alt-h"></i> Enhance Reply</a>
                        <ul class="dropdown-menu">
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_fix_grammar')">Fix Grammar</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_longer')">Expand / Make Longer</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_shorter')">Shorten</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite_translate')">Translate...</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('rewrite')">Standard Polish</a></li>
                        </ul>
                    </li>

                    <li class="dropdown-submenu">
                        <a tabindex="-1" href="javascript:void(0)"><i class="fas fa-language"></i> Language</a>
                        <ul class="dropdown-menu">
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('generate', 'auto')">Auto-Detect</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('generate', 'english')">English</a></li>
                            <li><a href="javascript:void(0)" onclick="generateAIResponse('generate', 'bangla')">Bangla (বাংলা)</a></li>
                        </ul>
                    </li>

                    <li class="dropdown-divider"></li>
                    <li><a href="javascript:void(0)" onclick="generateAIResponse('summarize')"><i class="fas fa-list-ul"></i> Summarize Ticket</a></li>
                    <li><a href="javascript:void(0)" onclick="generateAIResponse('triage')"><i class="fas fa-stethoscope"></i> Triage Ticket</a></li>
                    <li><a href="javascript:void(0)" onclick="generateAIResponse('generate_kb')"><i class="fas fa-book"></i> Generate KB Draft</a></li>
                </ul>
            </div>
            <div class="custom-prompt-container" style="display: inline-block; margin-left: 10px; vertical-align: middle;">
                <input type="text" id="aiCustomPrompt" class="form-control input-sm" placeholder="Custom instructions (optional)..." style="width: 250px; display: inline-block;" />
            </div>
            <div class="mood-display" style="display: inline-block; margin-left: 15px; vertical-align: middle;">
                <span id="currentMood" class="label label-default" style="font-size: 11px; padding: 5px 10px;">Mood: Analyzing...</span>
                <span id="confidenceBadge" class="label" style="font-size: 11px; padding: 5px 10px; margin-left: 5px;">Confidence: ...</span>
            </div>
        </div>
    `;
    
    // Position it before the reply content area (textarea)
    target.before(aiDropdown);
    
    // Analyze current ticket mood
    analyzeCurrentTicketMood({$ticket_id});
});

function generateAIResponse(mode, lang) {
    var ticketId = {$ticket_id};
    var replyArea = $('#replymessage');
    if (replyArea.length === 0) replyArea = $('#content'); // Fallback
    
    var currentMessage = replyArea.val();
    var mde = replyArea.data('markdown');
    
    if (mde) {
        currentMessage = mde.getContent();
    }
    
    var customPrompt = $('#aiCustomPrompt').val() || '';
    if (mode === 'rewrite_translate') {
        customPrompt = prompt("Which language should we translate this to?", "Spanish");
        if (!customPrompt) return;
        customPrompt = "Translate this to " + customPrompt;
    }
    
    // Show loading state
    var originalContent = currentMessage;
    var btn = $('#aiReplyBtn');
    var btnText = btn.find('.btn-text');
    var originalBtnHtml = btnText.html();
    
    btn.addClass('disabled').attr('disabled', 'disabled');
    btnText.html('<i class="fas fa-spinner fa-spin"></i> Processing...');

    if (mde) {
        mde.setContent('AI is processing your request... Please wait.');
    } else {
        replyArea.val('AI is processing your request... Please wait.');
        replyArea.attr('disabled', 'disabled');
    }
    
    $.post('supporttickets.php', {
        action: 'generate_ai_reply',
        ticket_id: ticketId,
        reply_mode: mode,
        reply_lang: lang || 'auto',
        current_message: currentMessage,
        custom_prompt: customPrompt
    }, function(response) {
        btn.removeClass('disabled').removeAttr('disabled');
        btnText.html(originalBtnHtml);

        if (mde) {
            // No need to removeAttr disabled for MDE
        } else {
            replyArea.removeAttr('disabled');
        }

        if (response.success) {
            if (mde) {
                mde.setContent(response.response);
            } else {
                replyArea.val(response.response);
            }

            if (response.mood && response.confidence) {
                updateMoodDisplay(response.mood, response.confidence);
            }
        } else {
            if (mde) {
                mde.setContent(originalContent);
            } else {
                replyArea.val(originalContent);
            }
            alert('AI Error: ' + response.error);
        }
    }, 'json').fail(function() {
        btn.removeClass('disabled').removeAttr('disabled');
        btnText.html(originalBtnHtml);
        
        if (!mde) replyArea.removeAttr('disabled');
        if (mde) {
            mde.setContent(originalContent);
        } else {
            replyArea.val(originalContent);
        }
        alert('Could not communicate with the AI module. Please try again.');
    });
}

function analyzeCurrentTicketMood(ticketId) {
    $.post('supporttickets.php', {
        action: 'analyze_ticket_mood',
        ticket_id: ticketId
    }, function(response) {
        if (response.success) {
            updateMoodDisplay(response.mood, response.confidence, response.escalation_level);
        }
    }, 'json');
}

function updateMoodDisplay(mood, confidence, escalation) {
    var moodLabel = $('#currentMood');
    var confidenceBadge = $('#confidenceBadge');
    
    moodLabel.text('Mood: ' + mood.charAt(0).toUpperCase() + mood.slice(1));
    moodLabel.removeClass('label-default label-success label-info label-warning label-danger');
    
    // Style based on mood
    var moodStyles = {
        'angry': 'label-danger',
        'frustrated': 'label-warning',
        'urgent': 'label-danger',
        'satisfied': 'label-success',
        'calm': 'label-success',
        'neutral': 'label-info'
    };
    moodLabel.addClass(moodStyles[mood.toLowerCase()] || 'label-info');

    var confidencePercent = Math.round(confidence * 100);
    confidenceBadge.text('Confidence: ' + confidencePercent + '%');
    confidenceBadge.removeClass('label-danger label-warning label-success');
    
    if (confidencePercent >= 80) {
        confidenceBadge.addClass('label-success');
    } else if (confidencePercent >= 60) {
        confidenceBadge.addClass('label-warning');
    } else {
        confidenceBadge.addClass('label-danger');
    }
    
    if (escalation > 0.7) {
        if ($('#needsHumanBadge').length === 0) {
            confidenceBadge.after(' <span id="needsHumanBadge" class="label label-danger"><i class="fas fa-exclamation-triangle"></i> Escalated</span>');
        }
    }
}
</script>
<style>
.ai-reply-container {
    padding: 12px;
    background: #fdfdfd;
    border: 1px solid #e1e4e8;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    width: 100%;
}
.dropdown-submenu {
    position: relative;
}
.dropdown-submenu > .dropdown-menu {
    top: 0;
    left: 100%;
    margin-top: -6px;
    margin-left: -1px;
    border-radius: 0 6px 6px 6px;
}
.dropdown-submenu:hover > .dropdown-menu {
    display: block;
}
.dropdown-submenu > a:after {
    display: block;
    content: " ";
    float: right;
    width: 0;
    height: 0;
    border-color: transparent;
    border-style: solid;
    border-width: 5px 0 5px 5px;
    border-left-color: #ccc;
    margin-top: 5px;
    margin-right: -10px;
}
.dropdown-submenu:hover > a:after {
    border-left-color: #fff;
}
.dropdown-submenu.pull-left {
    float: none;
}
.dropdown-submenu.pull-left > .dropdown-menu {
    left: -100%;
    margin-left: 10px;
    border-radius: 6px 0 6px 6px;
}
.dropdown-menu > li > a {
    padding: 8px 15px;
    font-size: 13px;
}
.dropdown-menu > li > a i {
    width: 20px;
    text-align: center;
    margin-right: 8px;
    color: #555;
}
</style>
HTML;
    }
});


// The AJAX request is now handled natively via gemini_ai_assistant_clientarea in the main module file.

// Hook to inject AI reply UI in client area
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if ($vars['filename'] == 'viewticket') {
        return <<<HTML
<script>
$(document).ready(function() {
    if ($('.client-ai-reply-container').length > 0) return; // Prevent duplicate injection
    
    // Check for standard WHMCS Six/Twenty-One theme reply area
    var target = $('#inputMessage');
    if (target.length === 0) {
        target = $('textarea[name="replymessage"]');
    }
    if (target.length === 0) {
        target = $('textarea[name="message"]');
    }
    
    if (target.length === 0) {
        return; // No reply area found
    }

    // Add alert about live agent
    var alertBanner = '<div class="alert alert-info" style="margin-bottom: 15px;"><i class="fas fa-info-circle"></i> If you want a real agent to assist you, please write <strong>"live agent"</strong> or similar words in your reply.</div>';
    
    var urlParams = new URLSearchParams(window.location.search);
    var tidMask = urlParams.get('tid');
    var ticketC = urlParams.get('c');

    var aiDropdown = `
        <div class="client-ai-reply-container" style="margin-bottom: 15px; padding: 12px; background: #fdfdfd; border: 1px solid #e1e4e8; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); width: 100%;">
            <div class="btn-group dropdown">
                <button type="button" id="clientAiReplyBtn" class="btn btn-sm btn-info dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="background-color: #31a7c5; border-color: #269abc; color: white; font-weight: bold;">
                    <i class="fas fa-robot"></i> <span class="btn-text">🤖 AI Reply</span> <span class="caret"></span>
                </button>
                <ul class="dropdown-menu" role="menu" aria-labelledby="dropdownMenu" style="margin-top: 5px; max-height: 400px; overflow-y: auto; min-width: 200px;">
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('generate')"><i class="fas fa-magic"></i> Generate Reply</a></li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('summarize')"><i class="fas fa-list-ul"></i> Summarize Ticket</a></li>
                    
                    <li class="dropdown-divider divider" style="margin: 8px 0; border-top: 1px solid #e5e5e5;"></li>
                    <li class="dropdown-header" style="padding: 3px 15px; font-size: 11px; color: #777; text-transform: uppercase; font-weight: bold;"><i class="fas fa-pen-nib"></i> Rewrite Tone</li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('rewrite_formal')" style="padding-left: 25px;">Formal</a></li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('rewrite_friendly')" style="padding-left: 25px;">Friendly</a></li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('rewrite_empathetic')" style="padding-left: 25px;">Empathetic</a></li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('rewrite_firm')" style="padding-left: 25px;">Firm/Direct</a></li>

                    <li class="dropdown-divider divider" style="margin: 8px 0; border-top: 1px solid #e5e5e5;"></li>
                    <li class="dropdown-header" style="padding: 3px 15px; font-size: 11px; color: #777; text-transform: uppercase; font-weight: bold;"><i class="fas fa-arrows-alt-h"></i> Change Length</li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('rewrite_longer')" style="padding-left: 25px;">Make Longer</a></li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('rewrite_shorter')" style="padding-left: 25px;">Make Shorter</a></li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('rewrite')" style="padding-left: 25px;">Standard Polish</a></li>

                    <li class="dropdown-divider divider" style="margin: 8px 0; border-top: 1px solid #e5e5e5;"></li>
                    <li class="dropdown-header" style="padding: 3px 15px; font-size: 11px; color: #777; text-transform: uppercase; font-weight: bold;"><i class="fas fa-language"></i> Language</li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('generate', 'auto')" style="padding-left: 25px;">Auto-Detect</a></li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('generate', 'english')" style="padding-left: 25px;">English</a></li>
                    <li><a href="javascript:void(0)" onclick="generateClientAIResponse('generate', 'bangla')" style="padding-left: 25px;">Bangla (বাংলা)</a></li>
                </ul>
            </div>
        </div>
    `;
    
    // Find the container for the reply textarea
    var replyContainer = target.closest('.form-group');
    if (replyContainer.length === 0) {
        replyContainer = target.parent();
    }
    
    replyContainer.before(alertBanner);
    target.before(aiDropdown);
    
    window.generateClientAIResponse = function(mode, lang) {
        var replyArea = target;
        var currentMessage = replyArea.val();
        
        var mde = null;
        if (typeof replyArea.data('markdown') !== 'undefined') {
            mde = replyArea.data('markdown');
            currentMessage = mde.getContent();
        }
        
        var originalContent = currentMessage;
        var btn = $('#clientAiReplyBtn');
        var btnText = btn.find('.btn-text');
        var originalBtnHtml = btnText.html();
        
        btn.addClass('disabled').attr('disabled', 'disabled');
        btnText.html('<i class="fas fa-spinner fa-spin"></i> Processing...');

        if (mde) {
            mde.setContent('AI is processing your request... Please wait.');
        } else {
            replyArea.val('AI is processing your request... Please wait.');
            replyArea.attr('disabled', 'disabled');
        }
        
        $.post('index.php?m=gemini_ai_assistant', {
            token: (typeof csrfToken !== 'undefined' ? csrfToken : $('input[name="token"]').val()),
            action: 'client_generate_ai_reply',
            tid_mask: tidMask,
            c: ticketC,
            reply_mode: mode,
            reply_lang: lang || 'auto',
            current_message: currentMessage
        }, function(response) {
            btn.removeClass('disabled').removeAttr('disabled');
            btnText.html(originalBtnHtml);

            if (!mde) {
                replyArea.removeAttr('disabled');
            }

            if (response && response.success) {
                if (mde) {
                    mde.setContent(response.response);
                } else {
                    replyArea.val(response.response);
                }
            } else {
                if (mde) {
                    mde.setContent(originalContent);
                } else {
                    replyArea.val(originalContent);
                }
                alert('AI Error: ' + (response ? response.error : 'Unknown error'));
            }
        }, 'json').fail(function() {
            btn.removeClass('disabled').removeAttr('disabled');
            btnText.html(originalBtnHtml);
            
            if (!mde) replyArea.removeAttr('disabled');
            
            if (mde) {
                mde.setContent(originalContent);
            } else {
                replyArea.val(originalContent);
            }
            alert('Could not communicate with the AI. Please try again.');
        });
    };
});
</script>
<style>
/* Dropdown hover effects for client area compatibility */
.client-ai-reply-container .dropdown-menu > li > a {
    padding: 6px 20px;
    clear: both;
    font-weight: 400;
    line-height: 1.42857143;
    color: #333;
    white-space: nowrap;
    display: block;
}
.client-ai-reply-container .dropdown-menu > li > a:hover {
    background-color: #f5f5f5;
    text-decoration: none;
    color: #262626;
}
.client-ai-reply-container .dropdown-header {
    display: block;
    margin-top: 5px;
    margin-bottom: 5px;
}
</style>
HTML;
    }
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

    return [
        // Core API Settings
        'gemini_api_key' => $settings['gemini_api_key'] ?? '',
        'gemini_model' => $settings['gemini_model'] ?? 'gemini-1.5-flash',
        'assistant_name' => $settings['assistant_name'] ?? 'AI Support',
        'auto_reply_enabled' => !empty($settings['auto_reply_enabled']),
        'confidence_threshold' => (float) ($settings['confidence_threshold'] ?? 0.7),
        'max_tokens' => (int) ($settings['max_tokens'] ?? 500),
        'temperature' => (float) ($settings['temperature'] ?? 0.7),
        'custom_instructions' => $settings['custom_instructions'] ?? '',
        'training_tickets_limit' => (int) ($settings['training_tickets_limit'] ?? 1000),
        'enable_debug_logging' => !empty($settings['enable_debug_logging']),

        // Footer Controls
        'ai_footer_enabled' => !empty($settings['ai_footer_enabled']) && $settings['ai_footer_enabled'] === 'on',
        'ai_footer_text' => $settings['ai_footer_text'] ?? "---\n**This response was generated by AI Assistant**",
        'ai_confidence_enabled' => !empty($settings['ai_confidence_enabled']) && $settings['ai_confidence_enabled'] === 'on',

        // Training & Customer Insights
        'enable_ticket_training' => !empty($settings['enable_ticket_training']),
        'enable_customer_insights' => !empty($settings['enable_customer_insights']),

        // Admin Escalation
        'admin_notification_email' => $settings['admin_notification_email'] ?? '',
        'escalation_auto_notify' => !empty($settings['escalation_auto_notify']),
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
    $message_lower = strtolower($message);

    $mood_indicators = [
        'angry' => ['angry', 'mad', 'pissed', 'furious', 'outrageous', 'ridiculous'],
        'frustrated' => ['frustrated', 'annoying', 'disappointed', 'fed up', 'sick of'],
        'urgent' => ['urgent', 'asap', 'emergency', 'immediately', 'right now'],
        'satisfied' => ['thanks', 'thank you', 'appreciate', 'great', 'awesome', 'perfect'],
        'calm' => ['hello', 'hi', 'please', 'could you', 'would you'],
        'neutral' => ['question', 'inquiry', 'help with', 'information']
    ];

    $scores = [];
    foreach ($mood_indicators as $mood => $indicators) {
        $score = 0;
        foreach ($indicators as $indicator) {
            if (strpos($message_lower, $indicator) !== false) {
                $score++;
            }
        }
        $scores[$mood] = $score;
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
                $task_description = "TASK: Summarize your current problem based on the ticket history.";
                $content_to_process = "TICKET MESSAGES:\n{$message}";
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
    @set_time_limit(60); // Give script more time for API retries
    $api_key = $config['gemini_api_key'];
    $model = $config['gemini_model'] ?? 'gemini-1.5-flash';
    $api_url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$api_key}";
    $max_retries = 3;
    $attempts = 0;
    $response_data = null;
    $last_error = '';

    // 🧠 Load custom AI behavior / features from configuration
    $custom_instruction = trim($config['custom_instructions'] ?? '');
    
    $system_instruction = "You are an AI support assistant. ";
    if (!empty($custom_instruction)) {
        $system_instruction .= "Follow these specific instructions: " . $custom_instruction . "\n\n";
    } else {
        $system_instruction .= "Your job is to generate helpful, clear, and professional replies.\n\n";
    }

    $system_instruction .= "CRITICAL: You MUST include a confidence score (0.0–1.0) based on your certainty.
You MUST return ONLY valid JSON in this exact format:
{
  \"reply\": \"Your helpful message to the customer\",
  \"confidence\": 0.0–1.0
}";

    // 🧩 Combine prompt with config instruction
    $instruction = "{$system_instruction}\n\nCustomer message:\n{$prompt}";

    $start_time = microtime(true);

    while ($attempts < $max_retries) {
        $attempts++;

        $data = [
            'contents' => [['parts' => [['text' => $instruction]]]],
            'generationConfig' => [
                'temperature' => (float) ($config['temperature'] ?? 0.5),
                'maxOutputTokens' => (int) ($config['max_tokens'] ?? 600),
            ]
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 45,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $response_time = round(microtime(true) - $start_time, 3);

        // ❌ Handle CURL or HTTP errors
        if ($curl_error) {
            $last_error = "CURL Error: $curl_error";
            log_api_call($api_url, $data, $response, $http_code, $curl_error, $response_time, $ticket_id, 'error', $last_error);
            continue;
        }
        if ($http_code !== 200) {
            $last_error = "HTTP Error $http_code";
            log_api_call($api_url, $data, $response, $http_code, null, $response_time, $ticket_id, 'error', $last_error);

            // 🛑 If rate limited (429) or server error (503), wait before retrying
            if ($http_code == 429 || $http_code == 503) {
                // Wait 4s then 10s for 429
                $wait_multiplier = ($http_code == 429) ? 4 : 2;
                $wait_time = pow($wait_multiplier, $attempts) * 1000000;
                usleep($wait_time);
            }
            continue;
        }

        $response_data = json_decode($response, true);
        if (!isset($response_data['candidates'][0]['content']['parts'][0]['text'])) {
            $last_error = 'Unexpected Gemini API format.';
            log_api_call($api_url, $data, $response, $http_code, null, $response_time, $ticket_id, 'error', $last_error);
            continue;
        }

        // 🧹 --- CLEAN & PARSE GEMINI RESPONSE ---
        $raw_text = trim($response_data['candidates'][0]['content']['parts'][0]['text']);
        $confidence = null;

        // Remove possible markdown code blocks
        $clean_text = preg_replace('/```(json)?/i', '', $raw_text);
        $clean_text = str_replace('```', '', $clean_text);
        $clean_text = trim($clean_text);

        // Try decoding JSON
        $parsed = json_decode($clean_text, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($parsed['reply'])) {
            $ai_text = trim($parsed['reply']);
            $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : null;
        } else {
            // fallback for malformed JSON
            $ai_text = preg_replace('/^{\s*"reply"\s*:\s*"|"\s*,\s*"confidence".*$/is', '', $clean_text);
            $ai_text = preg_replace('/["{}]/', '', $ai_text);
            $ai_text = trim($ai_text);
        }

        // ✅ Log success
        log_api_call($api_url, $data, $response_data, $http_code, null, $response_time, $ticket_id, 'success', null);

        return [
            'success' => true,
            'response' => $ai_text,
            'confidence' => $confidence,
            'model_used' => $model,
            'http_code' => $http_code,
            'attempts' => $attempts,
            'full_response' => $response_data
        ];
    }

    // ❌ All retries failed
    log_api_call(
        $api_url,
        $data ?? [],
        $response_data ?? [],
        $http_code ?? 0,
        $last_error,
        round(microtime(true) - $start_time, 3),
        $ticket_id,
        'error',
        $last_error
    );

    return [
        'success' => false,
        'error' => "Gemini API failed: {$last_error}",
        'model_used' => $model,
        'response_data' => $response_data
    ];
}







function log_api_call($endpoint, $request_data, $response_data, $http_code, $curl_error, $response_time, $ticket_id = null, $status = 'success', $error_details = null)
{
    $config = get_gemini_config();

    if (!$config['enable_debug_logging'] && $status == 'success') {
        return; // Only log errors if debug logging is disabled
    }

    try {
        Capsule::table('mod_gemini_api_logs')->insert([
            'api_endpoint' => $endpoint,
            'request_data' => is_array($request_data) ? json_encode($request_data, JSON_PRETTY_PRINT) : $request_data,
            'response_data' => is_array($response_data) ? json_encode($response_data, JSON_PRETTY_PRINT) : (string) $response_data,
            'http_code' => $http_code,
            'curl_error' => $curl_error,
            'response_time' => $response_time,
            'ticket_id' => $ticket_id,
            'status' => $status,
            'error_details' => $error_details,
            'created_at' => date('Y-m-d H:i:s')
        ]);
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
    if (empty($config['auto_reply_enabled'])) return;

    $now = date('Y-m-d H:i:s');
    $pending_replies = Capsule::table('mod_gemini_reply_queue')
        ->where('status', 'pending')
        ->where('scheduled_at', '<=', $now)
        ->limit(10) // process in batches
        ->get();

    foreach ($pending_replies as $reply) {
        Capsule::table('mod_gemini_reply_queue')->where('id', $reply->id)->update(['status' => 'processing']);
        
        $analysis = analyze_ticket_content($reply->user_message, $reply->ticket_id);
        $ai_response = generate_ai_ticket_response($reply->user_message, $reply->ticket_id, $analysis);

        if ($ai_response && $ai_response['success']) {
            add_ticket_reply($reply->ticket_id, $ai_response, $analysis);
            Capsule::table('mod_gemini_reply_queue')->where('id', $reply->id)->update(['status' => 'completed']);
            logActivity("Gemini AI: Processed queued autopilot reply for ticket #{$reply->ticket_id}");
        } else {
            Capsule::table('mod_gemini_reply_queue')->where('id', $reply->id)->update(['status' => 'failed']);
            logActivity("Gemini AI: Failed to process queued autopilot reply for ticket #{$reply->ticket_id} - " . ($ai_response['error'] ?? 'Unknown error'));
        }
    }
});