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

        $content_type_id = $criteria->get_contenttypeid();

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
        
        $sql = 'SELECT ' . ($need_distinct ? 'DISTINCT' : '') . '
                    `targetid`, `contenttypeid`
                FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `' . $type . '` = ' . $search_user_id . ' AND `vote` = "' . $value . '" AND `contenttypeid` = "' . $content_type_id . '"
                LIMIT ' . ($vbulletin->options['maxresults']);

        $posts = $db->query_read($sql);
        while ($post = $db->fetch_array($posts))
        {
            $target_id_list[] = $post['targetid'];
        }
        rsort($target_id_list, SORT_NUMERIC);
        
        foreach ($target_id_list as $target_id)
        {
            $results[] = array($content_type_id, $target_id);
        }
        return $results;
    }

}
