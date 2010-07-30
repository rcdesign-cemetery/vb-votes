<?php

// product version
define('VBVOTES_VERSION', '0.4');

// votes.js version
$version = (!$vbulletin->debug) ? VBVOTES_VERSION : TIMENOW;
define('VBVOTES_JS_VERSION', $version);

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
function get_votes_for_post_list($target_id_list, $content_type, $vote_type = NULL)
{

    global $vbulletin;
    $db = $vbulletin->db;
    static $target_votes;
    // if post id in static store, then remove id from search list and add value to result array
    // TODO refactor
    if (!empty($target_votes) AND is_array($target_votes))
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
        $content_type_id = vB_Types::instance()->getContentTypeID($content_type);
        $sql = 'SELECT
                    pv.`targetid`, pv.`contenttypeid`, pv.`vote`, pv.`fromuserid`, u.`username`
                FROM
                    ' . TABLE_PREFIX . 'votes AS pv
                LEFT JOIN
                    `user` AS u ON u.`userid` = pv.`fromuserid`
                WHERE
                    pv.`targetid` IN (' . implode($target_id_list, ', ') . ') AND pv.`contenttypeid` = "' . $content_type_id . '" ' . $vote_type_condition;
        $db_resource = $db->query_read($sql);
        $target_votes = array();
        while ($vote = $db->fetch_array($db_resource))
        {
            $target_votes[$vote['targetid']][$vote['vote']][] = array('fromuserid' => $vote['fromuserid'], 'username' => $vote['username']);
            $result[$vote['targetid']][$vote['vote']][] = array('fromuserid' => $vote['fromuserid'], 'username' => $vote['username']);
        }
    }
    if (is_null($result))
    {
        $result = array();
    }
    return $result;
}

/**
 * Generate URL-encoded query string
 *
 * @param array $options
 * @param string $script
 * @return string
 */
function create_vote_url($options, $script = 'vb_votes.php')
{
    if (is_null($options) OR !is_array($options))
    {
        return false;
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
    global $vbulletin;
    $db = $vbulletin->db;
    $sql = 'DELETE FROM
                `' . TABLE_PREFIX . 'votes`
            WHERE
                `fromuserid` = ' . $user_id;
    $db->query_write($sql);
    return true;
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
function delete_votes_by_target_id_list($target_id_list, $content_type)
{
    global $vbulletin;
    $db = $vbulletin->db;

    $content_type_id = vB_Types::instance()->getContentTypeID($content_type);
    $sql = 'DELETE FROM
                ' . TABLE_PREFIX . 'votes
            WHERE
                `targetid` IN(' . implode(', ', $target_id_list) . ') AND
                `contenttypeid` = "' . $content_type_id . '"';
    $db->query_write($sql);
    return true;
}


?>