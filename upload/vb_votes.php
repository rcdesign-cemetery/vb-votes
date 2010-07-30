<?php

// ####################### SET PHP ENVIRONMENT ###########################
error_reporting(E_ALL & ~E_NOTICE & ~8192);

// #################### DEFINE IMPORTANT CONSTANTS #######################
define('THIS_SCRIPT', 'vb_votes');


// ################### PRE-CACHE TEMPLATES AND DATA ######################
// get special phrase groups
$phrasegroups = array(
    'postbit',
);

// get special data templates from the datastore
$specialtemplates = array();

// pre-cache templates used by all actions
$globaltemplates = array();

// pre-cache templates used by specific actions
$actiontemplates = array();

// ######################### REQUIRE BACK-END ############################
require_once('./global.php');
require_once(DIR . '/includes/adminfunctions.php'); // required for can_administer
// #######################################################################
// ######################## START MAIN SCRIPT ############################
// #######################################################################


$vbulletin->input->clean_array_gpc('r', array(
    'targetid' => TYPE_INT,
    'contenttype' => TYPE_STR,
    'ajax' => TYPE_BOOL
));

$need_ajax_response = FALSE;

if (empty($vbulletin->GPC['contenttype']))
{
    $vbulletin->GPC['contenttype'] = 'vBForum_Post';
}

define('VOTE_CONTENT_TYPE', $vbulletin->GPC['contenttype']);

require_once(DIR . '/includes/class_votes.php');

$target_id = $vbulletin->GPC['targetid'];

// fetch target
switch (VOTE_CONTENT_TYPE)
{
    case 'vBForum_Post':
    default:
        $target = fetch_postinfo($target_id);
}

$permitted_actions = array(
    'vote',
    'remove',
);

if (in_array($_REQUEST['do'], $permitted_actions))
{
    // Init vote manager class
    $vote_manager = Votes::get_instance(VOTE_CONTENT_TYPE, $target);
    $need_ajax_response = TRUE;
}

if ($_REQUEST['do'] == 'vote')
{
    $vbulletin->input->clean_array_gpc('r', array(
        'value' => TYPE_BOOL,
    ));

    // check are negative votes forbidden
    if (!$vbulletin->GPC['value'] AND !$vbulletin->options['vbv_enable_neg_votes'])
    {
        standard_error(fetch_error('vbv_try_neg_vote'));
    }

    // if user can't vote for this item, throw standart error
    if (!$vote_manager->is_user_can_vote_item())
    {
        $vote_manager->throw_error();
    }

    // save vote into db
    $value = ($vbulletin->GPC['value'] ? '1' : '-1');
    $vote_manager->add_user_vote($vbulletin->userinfo['userid'], $value);

    // if post has too many negative votes, then send report
    if (!$vbulletin->GPC['value'] AND $vbulletin->options['vbv_neg_auto_report'] > 0)
    {
        $neg_votes_count = $vote_manager->get_votes_count(Votes::NEGATIVE);
        if ($neg_votes_count == $vbulletin->options['vbv_neg_auto_report'])
        {
            $reason = $vbphrase['vbv_neg_auto_report_msg'];
            require_once(DIR . '/includes/class_reportitem.php');
            switch (VOTE_CONTENT_TYPE)
            {
                case 'vBForum_Post':
                default:
                    $threadinfo = verify_id('thread', $target['threadid'], 0, 1);
                    $foruminfo = fetch_foruminfo($threadinfo['forumid']);
                    $reportobj = new vB_ReportItem_Post($vbulletin);
                    $reportobj->set_extrainfo('forum', $foruminfo);
                    $reportobj->set_extrainfo('thread', $threadinfo);
                    $reportobj->do_report($reason, $target);
            }
        }
    }

    // response
    if (!$vbulletin->GPC['ajax'])
    {
        // voted message + redirect
        $vbulletin->url = 'showthread.php?' . $vbulletin->session->vars['sessionurl_js'] . "p=$target[postid]#post$target[postid]";
        eval(print_standard_redirect('redirect_' . VOTE_CONTENT_TYPE . '_vote_add'));
    }
    $vote_button_style = 'none';
}

if ($_REQUEST['do'] == 'remove')
{
    $vbulletin->input->clean_array_gpc('r', array(
        'all' => TYPE_BOOL,
        'value' => TYPE_BOOL,
    ));
    if ($vbulletin->GPC['all'])
    {
        if (!can_administer())
        {
            print_no_permission();
        }
    }
    elseif (!$vbulletin->options['vbv_delete_own_votes'] OR $vote_manager->is_post_old())
    {
        print_no_permission();
    }

    if ($vbulletin->GPC['all'])
    {
        // remove all votes
        $vote_type =  ($vbulletin->GPC['value'] ? Votes::POSITIVE : Votes::NEGATIVE);
        $vote_manager->remove_all_user_votes($vote_type);
    }
    else
    {
        // remove single user vote
        $vote_manager->remove_user_vote($vbulletin->userinfo['userid']);
    }

    if (!$vbulletin->GPC['ajax'])
    {
        $vbulletin->url = 'showthread.php?' . $vbulletin->session->vars['sessionurl_js'] . "p=$target[postid]#post$target[postid]";
        eval(print_standard_redirect('redirect_' . VOTE_CONTENT_TYPE . '_vote_add'));
    }

    $vote_button_style = 'none';
    if ($vote_manager->is_user_can_vote_item())
    {
        $vote_button_style = '';
    }

    $need_ajax_response = TRUE;
}

if ($need_ajax_response)
{
    // create response for ajax
    require_once(DIR . '/includes/class_xml.php');

    $xml = new vB_AJAX_XML_Builder($vbulletin, 'text/xml');
    $xml->add_group('voting');

    // get votes results
    $vote_results = '';
    if (can_see_results())
    {
        $result_vote_type = NULL;
        if (!$vbulletin->options['vbv_enable_neg_votes'])
        {
            $result_vote_type = Votes::POSITIVE;
        }
        $votes = $vote_manager->get_votes_for_post($result_vote_type);

        $vote_results = $vote_manager->render_vote_result_bit(Votes::POSITIVE, $votes[Votes::POSITIVE]);
        $xml->add_tag('positive_votes', $vote_results);
        if ($vbulletin->options['vbv_enable_neg_votes'])
        {
            $vote_results = $vote_manager->render_vote_result_bit(Votes::NEGATIVE, $votes[Votes::NEGATIVE]);
            $xml->add_tag('negative_votes', $vote_results);
        }
    }

    // enable/disable vote buttons
    $xml->add_tag('vote_button_style', $vote_button_style);

    // return response
    $xml->close_group();
    $xml->print_xml();
}
print_stop_message('invalid_action_specified');
?>