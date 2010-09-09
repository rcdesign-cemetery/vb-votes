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

class vBForum_Search_SearchController_TopVotes extends vB_Search_SearchController
{

    public function get_results($user, $criteria)
    {
        global $vbulletin;
        $db = $vbulletin->db;

        $results = array();

        // vote value
        $equals_filters = $criteria->get_equals_filters();
        $value = $equals_filters['value'];
        $time_line = TIMENOW - 24 * 60 * 60 * $vbulletin->options['vbv_top_voted_days'];

        // sort by vote count
        $sql = 'SELECT
                    votes.contenttypeid, votes.targetid, CASE WHEN p.threadid IS NOT NULL THEN p.threadid 
                    WHEN gm.discussionid IS NOT NULL THEN gm.discussionid END as parentid
                FROM
                    ' . TABLE_PREFIX . 'votes as votes
                LEFT OUTER JOIN ' . TABLE_PREFIX . 'post as p ON postid = votes.targetid AND votes.contenttypeid=1
                LEFT OUTER JOIN ' . TABLE_PREFIX . 'groupmessage as gm ON gmid = votes.targetid  AND votes.contenttypeid=5
                WHERE
                     targetid IN (SELECT DISTINCT targetid
                                    FROM
                                    ' . TABLE_PREFIX . 'votes
                                    WHERE
                                    date > ' . $time_line . ' AND vote = "' . $value . '") 
                      AND vote = "' . $value . '"
                GROUP BY
                    targetid,contenttypeid
                ORDER BY
                    count(vote) DESC
                LIMIT ' . $vbulletin->options['maxresults'];
 
        $set = $vbulletin->db->query_read_slave($sql);
        while ($row = $vbulletin->db->fetch_row($set))
        {
            $results[] = $row;
        }
        $vbulletin->db->free_result($set);

        return array_values($results);
    }

}
