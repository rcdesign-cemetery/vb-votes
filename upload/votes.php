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
require_once(DIR . '/includes/functions_votes.php');

if ($_REQUEST['do'] == 'search')
{
    require_once(DIR . '/includes/functions_search.php');
    require_once(DIR . '/includes/functions_misc.php');

    $vbulletin->input->clean_array_gpc('r', array(
        'fromuserid'	=> TYPE_UINT,
        'touserid'	=> TYPE_UINT,
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
        // search top voted posts
        $time_line = TIMENOW - 24 * 60 * 60 * $vbulletin->options['vbv_top_voted_days'];

        $sql = 'SELECT
                    DISTINCT `targetid`
                FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `date` > ' . $time_line . ' AND `vote` = "' . $value . '" AND `targettype` = "' . VOTE_TARGET_TYPE . '"
                LIMIT ' . ($vbulletin->options['maxresults']);

        $target_id_list = array();
        $targets = $db->query_read($sql);
        while ($target = $db->fetch_array($targets))
        {
            $target_id_list[] = $target['targetid'];
        }
        // did we get some results?
        if (empty($target_id_list))
        {
            eval(standard_error(fetch_error('searchnoresults', ''), '', false));
        }
        $sql = 'SELECT
                    `targetid`, count(`vote`) AS vote_count
                FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `targetid` IN (' . implode($target_id_list, ', ') . ')  AND `targettype` = "' . VOTE_TARGET_TYPE . '" AND `vote` = "' . $value . '"
                GROUP BY
                    `targetid`
                ORDER BY
                    vote_count DESC';
        // build search hash
        $searchhash = md5(THIS_SCRIPT . TIMENOW . 'top' . $vote);
        unset($target_id_list);
        $title_type = 'top';
    }
    else
    {
        // search given or received votes by user
        if (!$vbulletin->GPC['fromuserid'] AND !$vbulletin->GPC['touserid'])
        {
            eval(standard_error(fetch_error('invalidid', $vbphrase['user'], $vbulletin->options['contactuslink'])));
        }
        $need_distinct = false;
        if ($vbulletin->GPC['fromuserid'])
        {
            // search by vote user
            $type = 'fromuserid';
            $search_user_id = $vbulletin->GPC['fromuserid'];
            $title_type = 'fromuser';
        }
        else
        {
            // search by voted post author
            $type = 'touserid';
            $search_user_id = $vbulletin->GPC['touserid'];
            $title_type = 'touser';
            $need_distinct = true;
        }
        // get user info
        if ($user = $db->query_first("SELECT userid, username FROM " . TABLE_PREFIX . "user WHERE userid = " . $search_user_id))
        {
            $searchuser =& $user['username'];
        }
        // could not find specified user
        else
        {
            eval(standard_error(fetch_error('invalidid', $vbphrase['user'], $vbulletin->options['contactuslink'])));
        }
        $searchhash = md5(THIS_SCRIPT . TIMENOW . $type . $search_user_id . $searchuser);
        $sql = 'SELECT ' . ($need_distinct ? 'DISTINCT' : '') . '
                    `targetid`, `targettype`
                FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `'. $type.'` = ' . $search_user_id . ' AND `vote` = "' . $value . '" AND `targettype` = "' . VOTE_TARGET_TYPE . '"
                LIMIT ' . ($vbulletin->options['maxresults']);
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

    // order by id (postid) for search from user profile
    if (!$vbulletin->GPC['top'])
    {
        rsort($orderedids, SORT_NUMERIC);
    }

    // set display terms
    $display = array(
        'words' => array(),
        'highlight' => array(),
        'common' => array(),
        'forums' => iif($showforums, $display['forums'], 0),
        'options' => array(
            'starteronly' => 0,
            'childforums' => 1,
            'action' => 'process'
        )
    );

    // set special display terms for votes
    if ('top' != $title_type)
    {
        $display['votes']['username'] = $searchuser;
    }
    $display['votes']['title_type'] = $title_type;
    $display['votes']['value'] = $value;

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

$target_id = $vbulletin->GPC['targetid'];
$target = fetch_postinfo($target_id);
$need_ajax_response = false;
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

    // check and throw error
    is_user_can_vote_item($target, true);

    // save vote into db
    $sql = 'INSERT INTO `' . TABLE_PREFIX . 'votes` (
                    `targetid` ,
                    `targettype` ,
                    `vote` ,
                    `fromuserid` ,
                    `touserid` ,
                    `date`
            )
            VALUES (
                    ' . $target_id .',
                    "' . VOTE_TARGET_TYPE . '",
                    "' . ($vbulletin->GPC['value'] ? '1': '-1') . '",
                    ' . $vbulletin->userinfo['userid'] . ',
                    ' . $target['userid'] . ',
                    ' . TIMENOW .
            ')';

    $db->query_write($sql);
    if (!$vbulletin->GPC['ajax'])
    {
        // voted message + redirect
        $vbulletin->url = 'showthread.php?' . $vbulletin->session->vars['sessionurl_js'] . "p=$target[postid]#post$target[postid]";
        eval(print_standard_redirect('redirect_'. VOTE_TARGET_TYPE .'_vote_add'));
    }
    $vote_button_style = 'none';
    $need_ajax_response = true;
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
    elseif (!$vbulletin->options['vbv_delete_own_votes'] OR is_post_old($target['dateline']))
    {
        print_no_permission();
    }

    $sql = 'DELETE FROM
                    `' . TABLE_PREFIX . 'votes`
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
        $sql .= '`fromuserid` = ' . $vbulletin->userinfo['userid'];
    }

    $db->query_write($sql);

    if (!$vbulletin->GPC['ajax'])
    {
        $vbulletin->url = 'showthread.php?' . $vbulletin->session->vars['sessionurl_js'] . "p=$target[postid]#post$target[postid]";
        eval(print_standard_redirect('redirect_'. VOTE_TARGET_TYPE .'_vote_add'));
    }

    if (!is_user_can_vote_item($target))
    {
        $vote_button_style = 'none';
    }
    $need_ajax_response = true;
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
        if (! $vbulletin->options['vbv_enable_neg_votes'])
        {
            $vote_type = '1';
        }
        $votes = get_votes_for_post($target_id, $vote_type);
        if (is_array($votes) AND !empty($votes))
        {
            foreach ($votes as $vote_type=>$user_voted_list)
            {
                $vote_results .= create_vote_result_bit($vote_type, $user_voted_list, $target, VOTE_TARGET_TYPE);
            }
        }
    }

    $xml->add_tag('vote_results', $vote_results);

    // enable/disable vote buttons
    $xml->add_tag('vote_button_style', $vote_button_style);

    // return response
    $xml->close_group();
    $xml->print_xml();
}
print_stop_message('invalid_action_specified');
?>