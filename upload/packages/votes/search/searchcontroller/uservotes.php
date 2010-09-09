<?php

if (!defined('VB_ENTRY'))
    die('Access denied.');


/**
 * @package Votes
 * @author rcdesign
 * @version
 * @copyright
 */
require_once(DIR . '/vb/search/searchcontroller.php');

class vBForum_Search_SearchController_UserVotes extends vB_Search_SearchController
{

    public function get_results($user, $criteria)
    {
        global $vbulletin;
        $db = $vbulletin->db;
        
        $results = array();

        // vote value
        $equals_filters = $criteria->get_equals_filters();

        $value = $equals_filters['value'];

        // search given or received votes by user
        $type = $equals_filters['type'];
        if (!in_array($type, array('fromuserid', 'touserid')))
        {
            throw new Exception('Unsuportied type. See `' . TABLE_PREFIX . 'votes` table');
        }
        $need_distinct = false;
        if ($type == 'touserid')
        {
            $need_distinct = true;
        }
        $search_user_id = $equals_filters['userid'];
        
        $sql = 'SELECT ' . ($need_distinct ? 'DISTINCT' : '') . ' votes.contenttypeid, votes.targetid,
                    CASE WHEN p.threadid IS NOT NULL THEN p.threadid 
                    WHEN gm.discussionid IS NOT NULL THEN gm.discussionid END as parentid,
                    CASE WHEN p.dateline IS NOT NULL THEN p.dateline
                    WHEN gm.dateline IS NOT NULL THEN gm.dateline END as orderdate
                FROM
                    ' . TABLE_PREFIX . 'votes as votes
                LEFT OUTER JOIN ' . TABLE_PREFIX . 'post as p ON postid = votes.targetid AND votes.contenttypeid=1
                LEFT OUTER JOIN ' . TABLE_PREFIX . 'groupmessage as gm ON gmid = votes.targetid AND votes.contenttypeid=5
                WHERE
                    ' . $type . ' = ' . $search_user_id . ' AND vote = "' . $value . '"
                ORDER BY orderdate DESC';

        $unset_id = 3;
        $set = $vbulletin->db->query_read_slave($sql);
        while ($row = $vbulletin->db->fetch_row($set))
        {
            $results[] = $row;
            unset($results[$row[$unset_id]][$unset_id]);
        }
        $vbulletin->db->free_result($set);
        return array_values($results);
    }

}
