<?php
define('VOTE_HANDLER_SCRIPT', 'votes.php');

/**
 * Create votes result html output
 *
 * @global vB_Registry $vbulletin
 * @global array $vbphrase
 * @global array $stylevar
 * @param string $vote_type
 * @param array $user_voted_list
 * @param array $target
 * @param string $target_type
 * @return string
 */
function create_vote_result_bit($vote_type, $user_voted_list, $target, $target_type = NULL)
{
    if (empty($user_voted_list))
    {
        return '';
    }
    global $vbulletin, $vbphrase, $stylevar;
    $vote_results = '';
    $votes = array();
    $votes['vote_list'] = '';
    $votes['remove_vote_link'] = '';
    $is_author = false;
    foreach ($user_voted_list as $voted_user)
    {
        eval('$user_vote_bit = "' . fetch_template('vote_postbit_user') . '";');
        $votes['vote_list'] .= $user_vote_bit;
        if ($voted_user['fromuserid'] == $vbulletin->userinfo['userid'])
        {
            $is_author = true;
        }
    }

    // add link remove own user vote
    if ($is_author AND $vbulletin->options['vbv_delete_own_votes'] AND !is_post_old($target['dateline']))
    {
        $votes['remove_vote_link'] = create_vote_url(array('do'=>'remove'));
    }

    $votes['target_id'] = $target['postid'];
    $votes['vote_type'] = 'Positive';
    $votes['post_user_votes'] = $vbphrase['vbv_positive_user_votes'];
    if ('-1' == $vote_type)
    {
        $votes['vote_type'] = 'Negative';
        $votes['post_user_votes'] = $vbphrase['vbv_negative_user_votes'];
    }
    require_once(DIR . '/includes/adminfunctions.php'); // required for can_administer
    if (can_administer())
    {
        $votes['remove_all_votes_link'] = create_vote_url(array('do'=>'remove', 'all'=>1, 'value'=>(string)$vote_type));
    }
    eval('$vote_results = "' . fetch_template('vote_postbit_info') . '";');

    return $vote_results;
}

/**
 * Get votes for single post
 * Note: The result will have the following format:
 * [vote_type] (1 / -1)
 *     [fromuserid]
 *     [username]
 *
 * @param int $target_id
 * @param string $vote_type
 * @param string $target_type
 * @return array
 */
function get_votes_for_post($target_id, $vote_type = NULL, $target_type = NULL)
{
    $target_id_list[] = $target_id;
    $result = get_votes_for_post_list($target_id_list, $vote_type, $target_type);
    return $result[$target_id];
}

/**
 * Get votes for post list
 * Note: The result will have the following format:
 * [target_id]
 *     [vote_type] (1 / -1)
 *         [fromuserid]
 *         [username]
 *
 * @global vB_Database $db
 * @staticvar array $target_votes
 * @param array $target_id_list
 * @param string $vote_type
 * @param string $target_type
 * @return array
 */
function get_votes_for_post_list($target_id_list, $vote_type = NULL, $target_type = NULL)
{
    if (is_null($target_type))
    {
        $target_type = VOTE_TARGET_TYPE;
    }
    global $db;
    static $target_votes;
    $result = array();
    // if post id in static store, then remove id from search list and add value to result array
    // TODO refactor
    if (!empty($target_votes) and is_array($target_votes))
    {
        foreach ($target_id_list as $post_id)
        {
            if (isset($target_id_list[$post_id]))
            {
                $result[$post_id] = $target_votes[$post_id];
                unset($target_id_list[$post_id]);
            }
        }
    }
    if (!empty($target_id_list))
    {
        $vote_type_condition = '';
        if (!is_null($vote_type))
        {
            $vote_type_condition = ' AND pv.`vote` = "' . $vote_type . '"';
        }
        $sql = 'SELECT
                    pv.`targetid`, pv.`targettype`, pv.`vote`, pv.`fromuserid`, u.`username`
                FROM
                    ' . TABLE_PREFIX . 'votes AS pv
                LEFT JOIN
                    `user` AS u ON u.`userid` = pv.`fromuserid`
                WHERE
                    pv.`targetid` IN (' . implode($target_id_list, ', ')  . ') AND pv.`targettype` = "' . $target_type . '" ' .$vote_type_condition;
        $db_resource = $db->query_read($sql);
        $target_votes = array();
        while ($vote = $db->fetch_array($db_resource))
        {
            $target_votes[$vote['targetid']][$vote['vote']][] = array('fromuserid'=>$vote['fromuserid'], 'username'=>$vote['username']);
            $result[$vote['targetid']][$vote['vote']][] = array('fromuserid'=>$vote['fromuserid'], 'username'=>$vote['username']);
        }
    }
    return $result;
}

/**
 * Check if user can vote for particular item?
 *
 * Note: if flag $throw_error set as true, then script halts execution
 * and shows the specified error message
 *
 * @global vB_Registry $vbulletin
 * @param array $target
 * @param bool $throw_error
 * @param string $target_type
 * @return string
 */
function is_user_can_vote_item($target, $throw_error = false, $target_type = NULL)
{
    global $vbulletin;
    $error = null;
    $target_id = $target['postid'];

    if (is_null($target_type))
    {
        $target_type = VOTE_TARGET_TYPE;
    }

    // user is not in banned, "disabled mod" or read only group
    $bann_groups = unserialize($vbulletin->options['vbv_grp_banned']);
    $read_only_groups = unserialize($vbulletin->options['vbv_grp_read_only']);
    if (!can_see_results() OR
        is_member_of($vbulletin->userinfo, $bann_groups) OR
        is_member_of($vbulletin->userinfo, $read_only_groups)
    )
    {
        if ($throw_error)
        {
            print_no_permission();
        }
        return false;
    }

    if ('forum' == $target_type)
    {
        // is this post old
        if (is_post_old($target['dateline']))
        {
            if ($throw_error)
            {
                standard_error(fetch_error('vbv_post_old'));
            }
            return false;
        }


        $threadinfo = fetch_threadinfo($target['threadid']);
        // this post is not in close forum
        $unvoted_forum = explode(',', $vbulletin->options['vbv_ignored_forums']);
        if (in_array($threadinfo['forumid'], $unvoted_forum))
        {
            if ($throw_error)
            {
                standard_error(fetch_error('vbv_post_can_not_be_voted'));
            }
            return false;
        }

        // user is not author of this topic
        if ( $vbulletin->userinfo['userid'] == $target['userid'])
        {

            if ($throw_error)
            {
                standard_error(fetch_error('vbv_your_post'));
            }
            return false;
        }
    }
    // user didn't vote for this post
    $votes_list = get_votes_for_post($target_id);
    if (is_array($votes_list))
    {
        foreach ($votes_list as $vote_type)
        {
            foreach ($vote_type as $vote)
            {
                if ($vote['fromuserid'] == $vbulletin->userinfo['userid'])
                {
                    if ($throw_error)
                    {
                        standard_error(fetch_error('vbv_post_voted'));
                    }
                    return false;
                }
            }
        }
    }
    // user have free vote
    if (!can_vote_today() )
    {
        if ($throw_error)
        {
            standard_error(fetch_error('vbv_to_many_votes_per_day'));
        }
        return false;
    }
    return true;
}

/**
 * Generate URL-encoded query string
 *
 * @param array $options
 * @param string $script
 * @return string
 */
function create_vote_url($options, $script = null)
{
    if (is_null($options) OR !is_array($options))
    {
        return false;
    }
    if (is_null($script))
    {
        $script = VOTE_HANDLER_SCRIPT;
    }
    return $script . '?' . http_build_query($options, '', '&amp;');
}

/**
 * Delete votes by user id
 *
 * @global vB_Database $db
 * @param int $user_id
 * @return bool
 */
function clear_votes_by_user_id($user_id)
{
    global $db;
    $sql = 'DELETE FROM
                `' . TABLE_PREFIX . 'votes`
            WHERE
                `fromuserid` = ' . $user_id;
    $db->query_write($sql);
    return true;
}

/**
 * Check day votes limit exceeded?
 *
 * @global vB_Database $db
 * @global vB_Registry $vbulletin
 * @staticvar array $result
 * @param int $user_id
 * @return bool
 */
function can_vote_today($user_id = null)
{
    global $db, $vbulletin;
    static $result;

    if (0 == $vbulletin->options['vbv_max_votes_daily'])
    {
        return true;
    }
    if (is_null($user_id))
    {
        $user_id = $vbulletin->userinfo['userid'];
    }
    if (!isset($result))
    {
        $result = false;
        $time_line = TIMENOW - (24 * 60 * 60 * 1);
        $sql = 'SELECT
                    count(`fromuserid`) as today_amount
                FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `fromuserid` = ' . $user_id .' AND
                    `date` >= ' . $time_line;
        $user_post_vote_amount = $db->query_first($sql);
        if ((int)$user_post_vote_amount['today_amount'] < (int)$vbulletin->options['vbv_max_votes_daily'])
        {
            $result = true;
        }
    }
    return $result;
}

/**
 * Check user can see votes result
 *
 * @global vB_Registry $vbulletin
 * @return bool
 */
function can_see_results()
{
    global $vbulletin;
    // user is not in "disabled mod" group
    $disable_mod_groups = unserialize($vbulletin->options['vbv_grp_disable']);
    if (is_member_of($vbulletin->userinfo, $disable_mod_groups))
    {
        return false;
    }
    return true;
}

/**
 * Delete votes for id list of target objects
 *
 * @global vB_Database $db
 * @param array $target_id_list
 * @param string $target_type
 * @return bool
 */
function delete_votes_by_target_id_list($target_id_list, $target_type = null)
{
    global $db;

    if (is_null($target_type))
    {
        $target_type = VOTE_TARGET_TYPE;
    }

    $sql = 'DELETE FROM
                ' . TABLE_PREFIX . 'votes
            WHERE
                `targetid` IN(' . implode(', ', $target_id_list) . ') AND
                `targettype` = "' . $target_type . '"';
    $db->query_write($sql);
    return trus;
}

/**
 * Check, is this post too old to be voted?
 *
 * @global vb_Registry $vbulletin
 * @param int $date
 * @return bool
 */
function is_post_old($date)
{
    global $vbulletin;
    if ((int)$vbulletin->options['vbv_post_days_old'] > 0)
    {
        $date_limit = TIMENOW - 24 * 60 * 60 * (int)$vbulletin->options['vbv_post_days_old'];
        return ($date < $date_limit);
    }
    return false;
}
?>