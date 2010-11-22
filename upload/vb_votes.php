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

$target_id = 0;
$target = array();
$target_id = $vbulletin->GPC['targetid'];
// fetch target
switch (VOTE_CONTENT_TYPE)
{
    case 'vBForum_Post':
        $target = fetch_postinfo($target_id);
        break;
    case 'vBForum_SocialGroupMessage':
        if ($vbulletin->options['vbv_enable_sg_votes'])
        {
            require_once(DIR . '/includes/functions_socialgroup.php');
            $target = fetch_groupmessageinfo($target_id);
            break;
        }
    default:
        standard_error(fetch_error('vbv_unsupported_type', $vbulletin->options['contactuslink']));
}

$permitted_actions = array(
    'vote',
    'remove',
);

if (in_array($_REQUEST['do'], $permitted_actions))
{
    // Init vote manager class
    $vote_manager = vtVotes::get_instance(VOTE_CONTENT_TYPE, $target);
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
    if (!$vote_manager->can_add_vote())
    {
        $vote_manager->show_error();
    }

    // save vote into db
    $value = ($vbulletin->GPC['value'] ? '1' : '-1');
    $vote_manager->add_vote($value);

    // if post has too many negative votes, then send report
    if (!$vbulletin->GPC['value'] AND $vbulletin->options['vbv_neg_auto_report'] > 0)
    {
        $neg_votes_count = $vote_manager->get_votes_count(vtVotes::NEGATIVE);
        if ($neg_votes_count == $vbulletin->options['vbv_neg_auto_report'])
        {
            $reason = $vbphrase['vbv_neg_auto_report_msg'];
            $vote_manager->report_item($reason);
        }
    }

    // response
    if (!$vbulletin->GPC['ajax'])
    {
        $url = 'showthread.php?' . $vbulletin->session->vars['sessionurl_js'] . "p=$target[postid]#post$target[postid]";
        if ('vBForum_SocialGroupMessage' == VOTE_CONTENT_TYPE)
        {
            $url = 'group.php?' . $vbulletin->session->vars['sessionurl_js'] . "do=discuss&discussionid=$target[discussionid]";;
        }
        $vbulletin->url = $url;
        eval(print_standard_redirect('redirect_' . VOTE_CONTENT_TYPE . '_vote_add'));
    }
    $vote_buttons_visibility = 'none';
}

if ($_REQUEST['do'] == 'remove')
{
    $vbulletin->input->clean_array_gpc('r', array(
        'all' => TYPE_BOOL,
        'value' => TYPE_BOOL,
    ));

    if ($vbulletin->GPC['all'])
    {
        if (can_administer())
        {
            // remove all votes
            $vote_type =  ($vbulletin->GPC['value'] ? vtVotes::POSITIVE : vtVotes::NEGATIVE);
            $vote_manager->remove_all_votes($vote_type);
        }
        else
        {
            print_no_permission();
        }
    }
    else
    {
        if ($vbulletin->options['vbv_delete_own_votes'] AND !$vote_manager->is_item_old())
        {
            // remove own vote
            $vote_manager->remove_vote();
        }
        else
        {
            print_no_permission();
        }
        
    }

    if (!$vbulletin->GPC['ajax'])
    {
        $url = 'showthread.php?' . $vbulletin->session->vars['sessionurl_js'] . "p=$target[postid]#post$target[postid]";
        if ('vBForum_SocialGroupMessage' == VOTE_CONTENT_TYPE)
        {
            $url = 'group.php?' . $vbulletin->session->vars['sessionurl_js'] . "do=discuss&discussionid=$target[discussionid]";;
        }
        $vbulletin->url = $url;
        eval(print_standard_redirect('redirect_' . VOTE_CONTENT_TYPE . '_vote_add'));
    }

    $vote_buttons_visibility = 'none';
    if ($vote_manager->can_add_vote())
    {
        $vote_buttons_visibility = '';
    }
}

if ($need_ajax_response)
{
    // create response for ajax
    require_once(DIR . '/includes/class_xml.php');

    $xml = new vB_AJAX_XML_Builder($vbulletin, 'text/xml');
    $xml->add_group('voting');

    // get votes results
    $vote_results = '';
    $disabled_group = unserialize($vbulletin->options['vbv_grp_disable']);
    if (!is_member_of($vbulletin->userinfo, $disabled_group))
    {
        $result_vote_type = NULL;
        if (!$vbulletin->options['vbv_enable_neg_votes'])
        {
            $result_vote_type = vtVotes::POSITIVE;
        }
        $votes = $vote_manager->get_item_votes($result_vote_type);

        $xml->add_tag('votes', $vote_manager->render_votes_block($votes, $target_id, $target_id));
    }

    // enable/disable vote buttons
    $xml->add_tag('vote_buttons_visibility', $vote_buttons_visibility);

    $item_id_name = 'post_';
    if (VOTE_CONTENT_TYPE == 'vBForum_SocialGroupMessage')
    {
        $item_id_name = 'gmessage_';
    }
    $xml->add_tag('item_id_name', $item_id_name);

    // return response
    $xml->close_group();
    $xml->print_xml();
}
print_stop_message('invalid_action_specified');
?>