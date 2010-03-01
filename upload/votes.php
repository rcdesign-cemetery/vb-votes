<?php

// ####################### SET PHP ENVIRONMENT ###########################
error_reporting(E_ALL & ~E_NOTICE & ~8192);

// #################### DEFINE IMPORTANT CONSTANTS #######################
define('THIS_SCRIPT', 'votepost');


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
define ('VOTE_TARGET_TYPE', 'forum');

$vbulletin->input->clean_array_gpc('r', array(
    'targetid'    => TYPE_INT, // post id
    'ajax'    => TYPE_BOOL 
));
$target_id = $vbulletin->GPC['targetid'];
require_once(DIR . '/includes/functions_votes.php');

if ($_REQUEST['do'] == 'search')
{
    require_once(DIR . '/includes/functions_search.php');
    require_once(DIR . '/includes/functions_misc.php');

    $vbulletin->input->clean_array_gpc('r', array(
        'userid'	=> TYPE_UINT,
        'authorid'	=> TYPE_UINT,
        'value' => TYPE_BOOL,
        'top' => TYPE_BOOL,
    ));

    if ($vbulletin->GPC['value'])
    {
        $value = '1';
    }
    else
    {
        $value = '-1';
    }

    if ($vbulletin->GPC['top'])
    {
        $time_line = TIMENOW - 24 * 60 * 60 * $vbulletin->options['vbv_top_voted_days'];
        // search top voted posts
        $sql = 'SELECT
                    `targetid`, count(`vote`) AS vote_count
                FROM
                    `' . TABLE_PREFIX . 'post_votes`
                WHERE
                    `date` > ' . $time_line . ' AND `vote` = "' . $value . '" AND `targettype` = "' . VOTE_TARGET_TYPE . '"
                GROUP BY
                    `targetid`
                ORDER BY
                    vote_count DESC
                LIMIT ' . ($vbulletin->options['maxresults'] * 2);
        // build search hash
        $forumchoice = $foruminfo['forumid'];
        $searchhash = md5(THIS_SCRIPT . TIMENOW . 'top' . $vote);
    }
    else
    {
        // search by vote user or search by voted post author
        if (!$vbulletin->GPC['userid'] AND !$vbulletin->GPC['authorid'])
        {
            eval(standard_error(fetch_error('invalidid', $vbphrase['user'], $vbulletin->options['contactuslink'])));
        }
        if ($vbulletin->GPC['userid'])
        {
            // search by vote user
            $type = 'userid';
            $search_user_id = $vbulletin->GPC['userid'];
        }
        else
        {
            // search by voted post author
            $type = 'postauthorid';
            $search_user_id = $vbulletin->GPC['authorid'];
        }
        // get user info
        if ($user = $db->query_first("SELECT userid, username, posts FROM " . TABLE_PREFIX . "user WHERE userid = " . $search_user_id))
        {
            $searchuser =& $user['username'];
        }
        // could not find specified user
        else
        {
            eval(standard_error(fetch_error('invalidid', $vbphrase['user'], $vbulletin->options['contactuslink'])));
        }
        $searchhash = md5(THIS_SCRIPT . TIMENOW . $type . $vbulletin->GPC['userid'] . $searchuser);
        $sql = 'SELECT
                    `targetid`, `targettype`
                FROM
                    `' . TABLE_PREFIX . 'post_votes`
                WHERE
                    `'. $type.'` = ' . $search_user_id . ' AND `vote` = "' . $value . '" AND `targettype` = "' . VOTE_TARGET_TYPE . '"
                ORDER BY
                    `date` DESC
                LIMIT ' . ($vbulletin->options['maxresults'] * 2);
    }

    // check if search already done
    if ($search = $db->query_first("SELECT searchid FROM " . TABLE_PREFIX . "search AS search WHERE searchhash = '" . $db->escape_string($searchhash) . "'"))
    {
    	$vbulletin->url = 'search.php?' . $vbulletin->session->vars['sessionurl'] . 'searchid='.$search['searchid'];
    	eval(print_standard_redirect('search'));
    }

    // start search timer
    $searchtime = microtime();

    // #############################################################################

    // query post ids in dateline DESC order...
    $orderedids = array();
    $posts = $db->query_read($sql);
    while ($post = $db->fetch_array($posts))
    {
        $orderedids[] = $post['targetid'];
    }
    unset($post);
    $db->free_result($posts);

    // did we get some results?
    if (empty($orderedids))
    {
        eval(standard_error(fetch_error('searchnoresults', ''), '', false));
    }

    // set display terms
    $display = array(
        'words' => array(),
        'highlight' => array(),
        'common' => array(),
        'users' => array($user['userid'] => $user['username']),
        'forums' => iif($showforums, $display['forums'], 0),
        'options' => array(
            'starteronly' => 0,
            'childforums' => 1,
            'action' => 'process'
        )
    );

    // end search timer
    $searchtime = number_format(fetch_microtime_difference($searchtime), 5, '.', '');

    /*insert query*/
    $db->query_write("
			REPLACE INTO " . TABLE_PREFIX . "search (userid, ipaddress, personal, searchuser, forumchoice, sortby, sortorder, searchtime, showposts, orderedids, dateline, displayterms, searchhash)
			VALUES (" . $vbulletin->userinfo['userid'] . ", '" . $db->escape_string(IPADDRESS) . "', 1, '" . $db->escape_string($user['username']) . "', '" . $db->escape_string($forumchoice) . "', 'post.dateline', 'DESC', $searchtime, 1, '" . $db->escape_string(implode(',', $orderedids)) . "', " . TIMENOW . ", '" . $db->escape_string(serialize($display)) . "', '" . $db->escape_string($searchhash) . "')
		");
    $searchid = $db->insert_id();

    $vbulletin->url = 'search.php?' . $vbulletin->session->vars['sessionurl'] . "searchid=$searchid";
    eval(print_standard_redirect('search'));
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

    $postinfo = fetch_postinfo($target_id);

    // check and throw error
    is_user_can_vote($target_id, true);

    // save vote into db
    $sql = 'INSERT INTO `' . TABLE_PREFIX . 'post_votes` (
                    `targetid` ,
                    `targettype` ,
                    `vote` ,
                    `userid` ,
                    `postauthorid` ,
                    `date`
            )
            VALUES (
                    ' . $target_id .',
                    "' . VOTE_TARGET_TYPE . '",
                    "' . ($vbulletin->GPC['value'] ? '1': '-1') . '",
                    ' . $vbulletin->userinfo['userid'] . ',
                    ' . $postinfo['userid'] . ',
                    ' . TIMENOW .
        ')';

    $db->query_write($sql);
    if (!$vbulletin->GPC['ajax'])
    {
        // voted message + redirect
        $vbulletin->url = 'showthread.php?' . $vbulletin->session->vars['sessionurl_js'] . "p=$postinfo[postid]#post$postinfo[postid]";
        eval(print_standard_redirect('redirect_'. VOTE_TARGET_TYPE .'_vote_add'));
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
    elseif (!$vbulletin->options['vbv_delete_own_votes'])
    {
        print_no_permission();
    }

    $sql = 'DELETE FROM
                    `' . TABLE_PREFIX . 'post_votes`
                WHERE
                    `targetid` = ' .$target_id . '  AND
                    `targettype` = "' . VOTE_TARGET_TYPE . '" AND ';

    if ($vbulletin->GPC['all'])
    {
        // remove all votes
        $sql .= '`vote` = "' . ($vbulletin->GPC['value'] ? '1': '-1') . '"';
    }
    else
    {
        // remove single user vote
        $sql .= '`userid` = ' . $vbulletin->userinfo['userid'];
    }

    $db->query_write($sql);

    if (!$vbulletin->GPC['ajax'])
    {
        $postinfo = fetch_postinfo($target_id);
        $vbulletin->url = 'showthread.php?' . $vbulletin->session->vars['sessionurl_js'] . "p=$postinfo[postid]#post$postinfo[postid]";
        eval(print_standard_redirect('redirect_'. VOTE_TARGET_TYPE .'_vote_add'));
    }


    $vote_button = '';
    if (!is_user_can_vote($target_id))
    {
        $vote_button_style = 'none';
    }
}


// create response for ajax
require_once(DIR . '/includes/class_xml.php');

$xml = new vB_AJAX_XML_Builder($vbulletin, 'text/xml');
$xml->add_group('voting');


$voted_table = '<div id="votes_buttons_$post[postid]"></div';
if (is_user_can_see_votes_result())
{
    if (! $vbulletin->options['vbv_enable_neg_votes'])
    {
        $vote_type = '1';
    }
    $votes = get_vote_for_post($target_id, $vote_type);
    if (is_array($votes) AND !empty($votes))
    {
        foreach ($votes as $vote_type=>$user_voted_list)
        {
            $voted_table .= create_voted_result($vote_type, $user_voted_list, $target_id);
        }
    }
}

$xml->add_tag('voted_div', $voted_table);

// enable/disable vote buttons
$xml->add_tag('vote_button_style', $vote_button_style);
// return response
$xml->close_group();
$xml->print_xml();

?>