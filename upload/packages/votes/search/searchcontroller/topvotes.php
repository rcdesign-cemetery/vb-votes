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
        $time_line_top = TIMENOW - 24 * 60 * 60 * $vbulletin->options['vbv_top_voted_days'];
        $time_line_votes = TIMENOW - 24 * 60 * 60 * 
            ($vbulletin->options['vbv_top_voted_days'] + $vbulletin->options['vbv_post_days_old']);

        $vote_count_limit = 0;
        if ($value == 1)
        {
            $vote_count_limit = $vbulletin->options['vbv_top_min_positive_count'];
        }
        else
        {
            $vote_count_limit = $vbulletin->options['vbv_top_min_negative_count'];
        }

        // sort by vote count
        $sql = 'SELECT
                    contenttypeid, targetid, CASE WHEN p.threadid IS NOT NULL THEN p.threadid 
                    WHEN gm.discussionid IS NOT NULL THEN gm.discussionid END as parentid
                FROM ( 
                    SELECT
                        contenttypeid, targetid, COUNT(vote) AS cnt, MIN(date) AS datecut
                    FROM
                        ' . TABLE_PREFIX . 'votes as votes
                    WHERE
                        date > ' . $time_line_votes . ' AND vote = "' . $value . '"
                    GROUP BY
                        targetid,contenttypeid
                    HAVING
                        datecut > ' . $time_line_top . ' AND cnt >= '. $vote_count_limit .'
                    ORDER BY
                        cnt DESC
                    LIMIT ' . $vbulletin->options['maxresults'] .'
                    ) res
                LEFT JOIN ' . TABLE_PREFIX . 'post as p ON postid = targetid AND contenttypeid=1
                LEFT JOIN ' . TABLE_PREFIX . 'groupmessage as gm ON gmid = targetid  AND contenttypeid=5';

        $set = $vbulletin->db->query_read_slave($sql);
        while ($row = $vbulletin->db->fetch_row($set))
        {
            $results[] = $row;
        }
        $vbulletin->db->free_result($set);

        return array_values($results);
    }

}
