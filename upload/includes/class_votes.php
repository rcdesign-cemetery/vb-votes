<?php

require_once(DIR . '/includes/class_bootstrap_framework.php');
vB_Bootstrap_Framework::init();

// product version
define('VBVOTES_VERSION', '0.7');

// votes.js version
$version = (!$vbulletin->debug) ? VBVOTES_VERSION : TIMENOW;
define('VBVOTES_JS_VERSION', $version);

/**
 * Class describing user permissions and status
 *
 */
class vtUserStatus
{    
    protected $registry = NULL;

    /**
     *
     * @global vB_Registry $vbulletin
     */
    public function __construct()
    {
        global $vbulletin;
        $this->registry = $vbulletin;
    }

    /**
     * Check if user is able to vote/remove votes
     *
     * @return bool
     */
    public function has_vote_permission()
    {
        $banned_group = unserialize($this->registry->options['vbv_grp_banned']);
        $disabled_group = unserialize($this->registry->options['vbv_grp_disable']);
        $readonly_group = unserialize($this->registry->options['vbv_grp_read_only']);
        if (is_member_of($this->registry->userinfo, $banned_group) OR
            is_member_of($this->registry->userinfo, $disabled_group) OR
            is_member_of($this->registry->userinfo, $readonly_group))
        {
            return false;
        }
        return true;
    }
    
    /**
     * Check if user has already voted. Search within votes list (to speed up the process)
     * Note: The input shall have the following format:
     * [item_id]
     *     [vote_type] (1 / -1)
     *         [fromuserid]
     *         [username]
     * @param array $votes_list
     * @return bool
     */
    public function is_user_vote_exists($votes_list)
    {
        if (is_array($votes_list))
        {
            foreach ($votes_list as $vote_type)
            {
                foreach ($vote_type as $vote)
                {
                    if ($vote['fromuserid'] == $this->registry->userinfo['userid'])
                    {
                        return true;
                    }
                }
            }
        }
        return false;
    }
    
    /**
     * Check if day votes limit exceeded
     *
     * @return bool
     */
    public function can_vote_today()
    {
        if (0 == $this->registry->options['vbv_max_votes_daily'])
        {
            return true;
        }

        $user_id = $this->registry->userinfo['userid'];

        $time_line = TIMENOW - (24 * 60 * 60 * 1);
        $sql = 'SELECT
                    count(`fromuserid`) as today_amount
                FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `fromuserid` = ' . $user_id . ' AND
                    `date` >= ' . $time_line;
        $user_post_vote_amount = $this->registry->db->query_first($sql);

        if ($user_post_vote_amount['today_amount'] >= $this->registry->options['vbv_max_votes_daily'])
        {
            return false;
        }
        return true;
    }
}

/**
 * User votes manager
 *
 */
abstract class vtVotes
{
    const POSITIVE = 1;
    const NEGATIVE = -1;

    private static $_instances = NULL;
    protected $error_msg = '';
    protected $item;
    protected $registry = NULL;
    private $_content_type_id;
    protected $userVotes_manager = NULL;
    protected $item_votes = array();

    /**
     * Item properties 
     */
    protected $item_id;
    protected $item_author_id;
    protected $is_item_deleted;
    protected $item_age;

    /**
     * Template names 
     */
    protected $voted_user_link_template = '';
    protected $votes_block_template = '';
    protected $vote_buttons_template = '';

    /**
     * Return vote instance or Null object
     * @todo extend types of $content_type
     *
     * @param string    $content_type
     * @param array     $item
     * @return object   instance of specified vote class
     */
    static function get_instance($content_type, $item = null)
    {
        $class_name = 'vtVotes' . '_' . $content_type;
        if (!self::$_instances[$content_type])
        {
            if (!class_exists($class_name))
            {
                standard_error(fetch_error('vbv_unsupported_type', $vbulletin->options['contactuslink']));
            }
            self::$_instances[$content_type] = new $class_name($content_type);
        }
        if (!is_null($item))
        {
            self::$_instances[$content_type]->set_item($item);
        }
        return self::$_instances[$content_type];
    }

    /**
     *
     * @global vB_Registry $vbulletin
     */
    public function __construct($content_type)
    {
        global $vbulletin;
        $this->registry = $vbulletin;
        if ($this->userVotes_manager == NULL) {
            $this->userVotes_manager = new vtUserStatus();
        }
        $this->_content_type_id = vB_Types::instance()->getContentTypeId($content_type);
    }

    /**
     * Sets the item data. Takes an array which consists of the different item parameters and assigns it the local array
     * @param array $item
     */
    public function set_item($item)
    {
        $this->item = $item;
        $this->set_item_data();
        $this->error_msg = '';
    }

    /**
     * Set item data in child class: item parameteres and template names
     */
    abstract public function set_item_data();

    /**
     * If item gets too many negative votes, report it
     */
    abstract public function report_item($reason);

    /**
     * Check if this item is too old to be voted
     *
     * @return bool
     */
    public function is_item_old()
    {
        if ((int) $this->registry->options['vbv_post_days_old'] > 0)
        {
            $date_limit = TIMENOW - 24 * 60 * 60 * (int) $this->registry->options['vbv_post_days_old'];
            return ($this->item_age < $date_limit);
        }
        return false;
    }
    
    /**
     * Get votes for item list
     * Note: The result will have the following format:
     * [item_id]
     *     [vote_type] (1 / -1)
     *         [fromuserid]
     *         [username]
     *
     * @param array $item_id_list
     * @param string $vote_type
     * @return array
     */
    public function get_items_list_votes($items_id_list, $vote_type = NULL)
    {
        if (!empty($this->item_votes) AND is_array($this->item_votes))
        {
            foreach ($items_id_list as $key => $post_id)
            {
                if (isset($this->item_votes[$post_id]))
                {
                    $result[$post_id] = $this->item_votes[$post_id];
                    unset($items_id_list[$key]);
                }
            }
        }
        if (!empty($items_id_list))
        {
            $vote_type_condition = '';
            if (!is_null($vote_type))
            {
                $vote_type_condition = ' AND pv.`vote` = "' . $vote_type . '"';
            }

            $sql = 'SELECT
                        pv.`targetid`, pv.`contenttypeid`, pv.`vote`, pv.`fromuserid`, u.`username`
                    FROM
                        ' . TABLE_PREFIX . 'votes AS pv
                    LEFT JOIN
                        ' . TABLE_PREFIX . 'user AS u ON u.`userid` = pv.`fromuserid`
                    WHERE
                        pv.`targetid` IN (' . implode($items_id_list, ', ') . ') AND pv.`contenttypeid` = "' . $this->_content_type_id . '" ' . $vote_type_condition;
            $db_resource = $this->registry->db->query_read($sql);
            
            foreach ($items_id_list as $id)
            {
                $this->item_votes[$id] = array();
            }

            while ($vote = $this->registry->db->fetch_array($db_resource))
            {
                $this->item_votes[$vote['targetid']][$vote['vote']][] = array('fromuserid' => $vote['fromuserid'], 'username' => $vote['username']);
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
     * Get votes for item
     * Note: The result will have the following format:
     * [vote_type] (1 / -1)
     *     [fromuserid]
     *     [username]
     *
     * @param string $vote_type
     * @return array
     */
    public function get_item_votes($vote_type = NULL, $sql_search = true)
    {
        $item_id_list[] = $this->item_id;
        $result = $this->get_items_list_votes($item_id_list, $vote_type, $sql_search);
        return $result[$this->item_id];
    }

    /**
     * Create html output for votes block (positive or negative)
     *
     * @global array    $vbphrase
     * @global array    $stylevar
     * @param string    $vote_type
     * @param array     $list_of_voted_users
     * @return string   HTML
     */
    public function render_votes_block($vote_type, $list_of_voted_users, $message_id = 0)
    {
        global $vbphrase, $stylevar;

        $votes['target_id'] = $this->item_id;
        $votes['vote_type'] = 'Positive';
        $votes['post_user_votes'] = $vbphrase['vbv_positive_user_votes'];
        if (vtVotes::NEGATIVE == $vote_type)
        {
            $votes['vote_type'] = 'Negative';
            $votes['post_user_votes'] = $vbphrase['vbv_negative_user_votes'];
        }

        $votes['vote_list'] = '';
        $votes['remove_vote_link'] = '';

        if (!empty($list_of_voted_users) AND is_array($list_of_voted_users))
        {
            $is_own_vote = false;
            $bits = array();
            foreach ($list_of_voted_users as $voted_user)
            {
                $rcd_vbv_templater = vB_Template::create($this->voted_user_link_template);
                $rcd_vbv_templater->register('voted_user', $voted_user);
                $user_vote_bit = $rcd_vbv_templater->render();

                $bits[] = $user_vote_bit;
                if ($voted_user['fromuserid'] == $this->registry->userinfo['userid'])
                {
                    $is_own_vote = true;
                }
            }

            $votes['vote_list'] = implode(', ', $bits);

            // add link remove own user vote
            if ($is_own_vote AND $this->registry->options['vbv_delete_own_votes'] AND !$this->is_item_old())
            {
                // replace then class will support extend types of $content_type
                $votes['remove_vote_link'] = $this->create_vote_url(array('do' => 'remove'));
                // $votes['remove_vote_link'] = create_vote_url(array('do' => 'remove', 'contenttype'=> $this->_content_type_id,));
            }

            require_once(DIR . '/includes/adminfunctions.php'); // required for can_administer
            if (can_administer ())
            {
                // replace then class will support extend types of $content_type
                $url_options = array('do' => 'remove', 'all' => 1, 'value' => (string) $vote_type);
                // $url_options = array('do' => 'remove', 'contenttype'=> $this->_content_type_id, 'all' => 1, 'value' => (string) $vote_type);
                $votes['remove_all_votes_link'] = $this->create_vote_url($url_options);
            }
        }
        $rcd_vbv_templater = vB_Template::create($this->votes_block_template);
        $rcd_vbv_templater->register('votes', $votes);
        if ($message_id > 0) {
            $rcd_vbv_templater->register('message_id', $message_id);
        }
        return $rcd_vbv_templater->render();
    }

    /**
     *  Render buttons
     *
     * @global array    $show
     * @return string   HTML
     */
    public function render_vote_buttons()
    {
        global $show;

        $item_buttons_hide = ($show['vote_button_hide'] OR !$this->is_vote_buttons_enabled());
        if (!$show['vote_button_hide'])
        {
            if ($this->registry->options['vbv_enable_neg_votes'])
            {
                $show['negative'] = true;
            }
        }

        $vote_link = $this->create_vote_url(array('do' => 'vote', 'targetid' => $this->item_id));
        $rcd_vbv_templater = vB_Template::create($this->vote_buttons_template);
        $rcd_vbv_templater->register('vote_link', $vote_link);
        $rcd_vbv_templater->register('target_id', $this->item_id);
        $rcd_vbv_templater->register('target_buttons_hide', $item_buttons_hide);
        return $rcd_vbv_templater->render();
    }


    /**
     * Should we show vote buttons or not, check 
     *
     * @return bool
     */
    protected function is_vote_buttons_enabled()
    {
        $ignorelist = preg_split('/( )+/', trim($this->registry->userinfo['ignorelist']), -1, PREG_SPLIT_NO_EMPTY);

        if ($this->is_item_deleted OR
            in_array($this->item_author_id, $ignorelist) OR 
            $this->registry->userinfo['userid'] == $this->item_author_id)
        {
            $this->error_msg = 'vbv_post_can_not_be_voted';
        }

        if (!$this->userVotes_manager->has_vote_permission())
        {
            $this->error_msg = 'nopermission_loggedin';
        }

        if ($this->is_item_old())
        {
            $this->error_msg = 'vbv_post_old';
        }

        if ($this->userVotes_manager->is_user_vote_exists($this->get_item_votes()))
        {
            $this->error_msg = 'vbv_post_voted';
        }

        if (!empty($this->error_msg)) {
            return false;
        }

        return true;
    }

    /**
     * Check if voting possible for today
     *
     * @return bool
     */
    public function can_add_vote()
    {
        $this->is_vote_buttons_enabled();
            
        if (!$this->userVotes_manager->can_vote_today())
        {
            $this->error_msg = 'vbv_to_many_votes_per_day';
        }
        
        if (!empty($this->error_msg)) {
            return false;
        }

        return true;
    }

    /**
     *  Use vbulletin native system, for throwing error
     *
     * @return bool
     */
    public function show_error()
    {
        if (!empty($this->error_msg))
        {
            if ('nopermission_loggedin' == $this->error_msg)
            {
                print_no_permission();
            }
            standard_error(fetch_error($this->error_msg));
        }
        return false;
    }

    /**
     * Generate URL-encoded query string
     *
     * @param array $options
     * @param string $script
     * @return string
     */
    static function create_vote_url($options, $script = 'vb_votes.php')
    {
        if (is_null($options) OR !is_array($options))
        {
            return false;
        }
        return $script . '?' . http_build_query($options, '', '&amp;');
    }

    /**
     * Get votes count by vote_type
     *
     * @param int $vote_type
     * @return bool
     */
    public function get_votes_count($vote_type = NULL)
    {
        if (!is_null($vote_type))
        {
            $vote_type_condition = ' AND pv.`vote` = "' . $vote_type . '"';
        }
        $sql = 'SELECT
                    count(pv.`targetid`) AS vote_cont
                FROM
                    ' . TABLE_PREFIX . 'votes AS pv
                LEFT JOIN
                    `user` AS u ON u.`userid` = pv.`fromuserid`
                WHERE
                    pv.`targetid` = ' . $this->item_id . ' AND pv.`contenttypeid` = "' . $this->_content_type_id . '" ' . $vote_type_condition;
        $query_result = $this->registry->db->query_first($sql);
        return $query_result['vote_cont'];
    }


     /**
     * Voting
     *
     * @param int $vote_type
     * @return bool
     */
    public function add_vote($vote_type)
    {
        $sql = 'INSERT INTO `' . TABLE_PREFIX . 'votes` (
                    `targetid` ,
                    `contenttypeid` ,
                    `vote` ,
                    `fromuserid` ,
                    `touserid` ,
                    `date`
            )
            VALUES (
                    ' . $this->item_id . ',
                    "' . $this->_content_type_id . '",
                    "' . $vote_type . '",
                    ' . $this->registry->userinfo['userid'] . ',
                    ' . $this->item_author_id . ',
                    ' . TIMENOW .
            ')';
        $this->registry->db->query_write($sql);
        $this->item_votes = array();
        return true;
    }    
    
     /**
     *  Clear votes for item by vote type
     *
     * @param int $vote_type
     * @return bool
     */
    public function remove_all_votes($vote_type)
    {
        $sql = 'DELETE FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `targetid` = ' . $this->item_id . '  AND
                    `contenttypeid` = "' . $this->_content_type_id . '" AND
                    `vote` = "' . $vote_type . '"';

        $this->registry->db->query_write($sql);
        $this->item_votes = array();
        return true;
    }

    /**
     *  Removes only votes posted by current user
     *
     * @return bool
     */
    public function remove_vote()
    {
        $sql = 'DELETE FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `targetid` = ' . $this->item_id . '  AND
                    `contenttypeid` = "' . $this->_content_type_id . '" AND
                    `fromuserid` = ' . $this->registry->userinfo['userid'];


        $this->registry->db->query_write($sql);
        $this->item_votes = array();
        return true;
    }

    /**
     * Delete all votes for selected user
     *
     * @global vB_Database $db
     * @param int $user_id
     * @return bool
     */
    static function remove_votes_by_user_id($user_id)
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
     * Delete votes for id list of target objects
     *
     * @global vB_Database $db
     * @param array $target_id_list
     * @param string $target_type
     * @return bool
     */
    static function remove_votes_by_target_id_list($items_id_list, $content_type)
    {
        global $vbulletin;
        $db = $vbulletin->db;

        $content_type_id = vB_Types::instance()->getContentTypeID($content_type);
        $sql = 'DELETE FROM
                    ' . TABLE_PREFIX . 'votes
                WHERE
                    `targetid` IN(' . implode(',',$items_id_list) . ') AND
                    `contenttypeid` = "' . $content_type_id . '"';
        $db->query_write($sql);
        return true;
    }
}

/**
 * Vote for vBForum Post
 */
class vtVotes_vBForum_Post extends vtVotes
{
    public function set_item_data()
    {
        $this->item_id = $this->item['postid'];
        $this->item_author_id = $this->item['userid'];
        $this->is_item_deleted = $this->item['isdeleted'];
        $this->item_age = $this->item['dateline'];
        
        $this->voted_user_link_template = 'vote_postbit_user';
        $this->votes_block_template = 'vote_postbit_info';
        $this->vote_buttons_template = 'vote_postbit_buttons';
    }
    
    public function report_item($reason)
    {
        require_once(DIR . '/includes/class_reportitem.php');
        $threadinfo = verify_id('thread', $this->item['threadid'], 0, 1);
        $foruminfo = fetch_foruminfo($threadinfo['forumid']);
        $reportobj = new vB_ReportItem_Post($this->registry);
        $reportobj->set_extrainfo('forum', $foruminfo);
        $reportobj->set_extrainfo('thread', $threadinfo);
        $reportobj->do_report($reason, $this->item);
    }

    /**
     * Can user vote. All checks.
     * Override parent method.
     *
     * @return bool
     */
    protected function is_vote_buttons_enabled()
    {
        parent::is_vote_buttons_enabled();
        if ($this->_is_forum_closed())
        {
            $this->error_msg = 'vbv_post_can_not_be_voted';
        }

        if (!empty($this->error_msg)) {
            return false;
        }

        return true;
    }

    /**
     *  Check forum status
     *
     * @return bool
     */
    private function _is_forum_closed()
    {
        $threadinfo = fetch_threadinfo($this->item['threadid']);
        $ignored_forums = explode(',', $this->registry->options['vbv_ignored_forums']);
        if ($this->registry->options['vbv_ignore_forum_childs'])
        {
            $foruminfo = fetch_foruminfo($threadinfo['forumid']);
            foreach($ignored_forums as $ignored_forum_id)
            {
                if (in_array(intval($ignored_forum_id), explode(',', $foruminfo['parentlist'])))
                {
                    return true;
                }
            }
        }
        else
        {
            if (in_array($threadinfo['forumid'], $ignored_forums))
            {
                return true;
            }
        }
        return false;
    }

}

class vtVotes_vBForum_SocialGroupMessage extends vtVotes
{
    public function set_item_data()
    {
        $this->item_id = $this->item['gmid'];
        $this->item_author_id = $this->item['postuserid'];
        $this->is_item_deleted = false;
        if ($this->item['state'] == 'deleted')
        {
            $this->is_item_deleted = true;
        }
        $this->item_age = $this->item['dateline'];

        $this->voted_user_link_template = 'vote_postbit_user';
        $this->votes_block_template = 'sg_vote_info';
        $this->vote_buttons_template = 'sg_vote_buttons';
    }

    /**
     * Override parent method
     *
     * @return bool
     */
    protected function is_vote_buttons_enabled()
    {
        parent::is_vote_buttons_enabled();
        require_once(DIR . '/includes/functions_socialgroup.php');
        $discussion = fetch_socialdiscussioninfo($this->item['discussionid']);
        $group = fetch_socialgroupinfo($discussion['groupid']);
        if (can_post_new_message($group))
        {
            
        } else {$this->error_msg = 'vbv_post_can_not_be_voted';}

        if (!empty($this->error_msg)) {
            return false;
        }

        return true;
    }

    public function report_item($reason)
    {
        require_once(DIR . '/includes/functions_socialgroup.php');
        $discussion = fetch_socialdiscussioninfo($this->item['discussionid']);
        $group = fetch_socialgroupinfo($discussion['groupid']);
        require_once(DIR . '/includes/class_reportitem.php');
        $reportobj = new vB_ReportItem_GroupMessage($vbulletin);
        $reportobj->set_extrainfo('group', $group);
        $reportobj->set_extrainfo('discussion', $discussion);
        $reportobj->do_report($reason, $this->item);
    }
}
?>
