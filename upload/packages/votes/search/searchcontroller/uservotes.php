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

        $search_user_id = $equals_filters['userid'];
        $sql = '';

        if ($type == 'touserid')
        {
            $sql = 'SELECT contenttypeid, targetid, CASE WHEN p.threadid IS NOT NULL THEN p.threadid 
                    WHEN gm.discussionid IS NOT NULL THEN gm.discussionid END as parentid
                FROM  (SELECT DISTINCT contenttypeid, targetid
                       FROM ' . TABLE_PREFIX . 'votes
                       WHERE
                           touserid = ' . $search_user_id . ' AND vote = "' . $value . '"
                     ) res
                LEFT OUTER JOIN ' . TABLE_PREFIX . 'post as p ON postid = targetid AND contenttypeid=1
                LEFT OUTER JOIN ' . TABLE_PREFIX . 'groupmessage as gm ON gmid = targetid AND contenttypeid=5
                ';
        } else {
            $sql = 'SELECT contenttypeid, targetid, CASE WHEN p.threadid IS NOT NULL THEN p.threadid 
                    WHEN gm.discussionid IS NOT NULL THEN gm.discussionid END as parentid
                FROM  (SELECT contenttypeid, targetid
                       FROM ' . TABLE_PREFIX . 'votes
                       WHERE
                           fromuserid = ' . $search_user_id . ' AND vote = "' . $value . '"
                     ) res
                LEFT OUTER JOIN ' . TABLE_PREFIX . 'post as p ON postid = targetid AND contenttypeid=1
                LEFT OUTER JOIN ' . TABLE_PREFIX . 'groupmessage as gm ON gmid = targetid AND contenttypeid=5
                ';
        }

        $set = $vbulletin->db->query_read_slave($sql);
        while ($row = $vbulletin->db->fetch_row($set))
        {
            $results[] = $row;
        }
        $vbulletin->db->free_result($set);

        $sorted_results = array_values($results);
        rsort($sorted_results);
        return $sorted_results;
    }

}
