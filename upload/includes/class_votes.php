<?php

require_once(DIR . '/includes/functions_votes.php');
require_once(DIR . '/includes/class_bootstrap_framework.php');
vB_Bootstrap_Framework::init();

/**
 * User votes manager
 *
 */
abstract class Votes
{
    const POSITIVE = 1;
    const NEGATIVE = -1;

    private static $_instances = NULL;
    public $error_msg = '';
    protected $_target;
    protected $_target_id;
    protected $registry = NULL;
    protected $_content_type_id;

    /**
     * Return vote instance or Null object
     * @todo extend types of $content_type
     *
     * @param string    $content_type
     * @param array     $target
     * @return object   instance of specified vote class
     */
    static function get_instance($content_type, $target = null)
    {
        $class_name = 'Votes' . '_' . $content_type;
        if (!self::$_instances[$content_type])
        {
            if (!class_exists($class_name))
            {
                return new Votes_Null();
            }
            self::$_instances[$content_type] = new $class_name($content_type);
        }
        if (!is_null($target))
        {
            self::$_instances[$content_type]->set_target($target);
        }
        self::$_instances[$content_type]->init();
        return self::$_instances[$content_type];
    }

    public function __construct($content_type)
    {
        $this->_content_type_id = vB_Types::instance()->getContentTypeId($content_type);
    }

    /**
     *
     * @global vB_Registry $vbulletin
     */
    public function init()
    {
        global $vbulletin;
        $this->registry = $vbulletin;
    }

    public function set_target($target)
    {
        $this->_target = $target;
        $this->set_target_id();
    }

    /**
     * Getting id from target object
     * Must by specified in chaild class
     */
    abstract public function set_target_id();

    /**
     * Create votes result html output
     *
     * @global array    $vbphrase
     * @global array    $stylevar
     * @param string    $vote_type
     * @param array     $list_of_voted_users
     * @return string   HTML
     */
    public function render_vote_result_bit($vote_type, $list_of_voted_users)
    {
        global $vbphrase, $stylevar;

        $votes['target_id'] = $this->_target_id;
        $votes['vote_type'] = 'Positive';
        $votes['post_user_votes'] = $vbphrase['vbv_positive_user_votes'];
        if (Votes::NEGATIVE == $vote_type)
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
                $rcd_vbv_templater = vB_Template::create('vote_postbit_user');
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
            if ($is_own_vote AND $this->registry->options['vbv_delete_own_votes'] AND !$this->is_post_old($this->_target['dateline']))
            {
                // replace then class will support extend types of $content_type
                $votes['remove_vote_link'] = create_vote_url(array('do' => 'remove'));
                // $votes['remove_vote_link'] = create_vote_url(array('do' => 'remove', 'contenttype'=> $this->_content_type_id,));
            }

            require_once(DIR . '/includes/adminfunctions.php'); // required for can_administer
            if (can_administer ())
            {
                // replace then class will support extend types of $content_type
                $url_options = array('do' => 'remove', 'all' => 1, 'value' => (string) $vote_type);
                // $url_options = array('do' => 'remove', 'contenttype'=> $this->_content_type_id, 'all' => 1, 'value' => (string) $vote_type);
                $votes['remove_all_votes_link'] = create_vote_url($url_options);
            }
        }
        $rcd_vbv_templater = vB_Template::create('vote_postbit_info');
        $rcd_vbv_templater->register('votes', $votes);
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

        $target_buttons_hide = ($show['vote_button_hide'] OR $this->_is_vote_button_hide());
        if (!$show['vote_button_hide'])
        {
            if ($this->registry->options['vbv_enable_neg_votes'])
            {
                $show['negative'] = true;
            }
        }

        $vote_link = create_vote_url(array('do' => 'vote', 'targetid' => $this->_target_id));
        $rcd_vbv_templater = vB_Template::create('vote_postbit_buttons');
        $rcd_vbv_templater->register('vote_link', $vote_link);
        $rcd_vbv_templater->register('target_id', $this->_target_id);
        $rcd_vbv_templater->register('target_buttons_hide', $target_buttons_hide);
        return $rcd_vbv_templater->render();
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
     * @return array
     */
    public function get_votes_for_post($vote_type = NULL)
    {
        $target_id_list[] = $this->_target_id;
        $result = get_votes_for_post_list($target_id_list, $this->_content_type_id, $vote_type);
        return $result[$this->_target_id];
    }

    /**
     * Check permissions. User is not in banned, "disabled mod" or read only group
     *
     * @return bool
     */
    protected function _has_user_permission()
    {
        $bann_groups = unserialize($this->registry->options['vbv_grp_banned']);
        if (
            is_member_of($this->registry->userinfo, $bann_groups) OR
            $this->_is_user_in_disable_mod_groups() OR
            $this->_is_user_in_read_only_group()
        )
        {
            throw new Exception('nopermission_loggedin');
        }
        return true;
    }

    /**
     *  User is not in read only group
     *
     * @return bool
     */
    protected function _is_user_in_read_only_group()
    {
        $read_only_groups = unserialize($this->registry->options['vbv_grp_read_only']);
        if (is_member_of($this->registry->userinfo, $read_only_groups))
        {
            return true;
        }
        return false;
    }

    /**
     *  User is not author of target topic
     *
     * @return bool
     */
    protected function _is_author()
    {
        if ($this->registry->userinfo['userid'] == $this->_target['userid'])
        {
            return true;
        }
        return false;
    }

    /**
     * Check, post is deleted or author in ignore list
     *
     * @return bool
     */
    protected function _is_post_prohibited_for_voting()
    {
        $ignorelist = preg_split('/( )+/', trim($this->registry->userinfo['ignorelist']), -1, PREG_SPLIT_NO_EMPTY);
        if ($this->_target['isdeleted'] OR in_array($this->_target['userid'], $ignorelist))
        {
            throw new Exception('vbv_post_can_not_be_voted');
        }
        return false;
    }

    /**
     * User didn't vote for this post
     *
     * @return bool
     */
    protected function _is_already_voted()
    {
        $votes_list = $this->get_votes_for_post();
        if (is_array($votes_list))
        {
            foreach ($votes_list as $vote_type)
            {
                foreach ($vote_type as $vote)
                {
                    if ($vote['fromuserid'] == $this->registry->userinfo['userid'])
                    {
                        throw new Exception('vbv_post_voted');
                    }
                }
            }
        }
        return false;
    }

    /**
     * Can user vote. All checks, except day votes limit.
     *
     * @return bool
     */
    protected function _vote_access_checks()
    {
        $this->_is_post_prohibited_for_voting();

        $this->_has_user_permission();

        if ($this->_is_author())
        {
            throw new Exception('vbv_post_can_not_be_voted');
        }

        // is this post old
        if ($this->is_post_old())
        {
            throw new Exception('vbv_post_old');
        }

        $this->_is_already_voted();

        return true;
    }

    /**
     * Check day votes limit exceeded?
     *
     * @staticvar array $result
     * @return bool
     */
    protected function _can_vote_today()
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

        if ((int) $user_post_vote_amount['today_amount'] >= (int) $this->registry->options['vbv_max_votes_daily'])
        {
            throw new Exception('vbv_to_many_votes_per_day');
        }
        return true;
    }

    /**
     * User is not in "disabled mod" group
     *
     * @return true
     */
    protected function _is_user_in_disable_mod_groups()
    {
        $disable_mod_groups = unserialize($this->registry->options['vbv_grp_disable']);
        if (is_member_of($this->registry->userinfo, $disable_mod_groups))
        {
            return true;
        }
        return false;
    }

    /**
     * Check, is this post too old to be voted?
     *
     * @global vb_Registry $vbulletin
     * @return bool
     */
    public function is_post_old()
    {
        global $vbulletin;
        if ((int) $this->registry->options['vbv_post_days_old'] > 0)
        {
            $date_limit = TIMENOW - 24 * 60 * 60 * (int) $vbulletin->options['vbv_post_days_old'];
            return ($this->_target['dateline'] < $date_limit);
        }
        return false;
    }

    /**
     * Check if user can vote for particular item?
     *
     * @return bool
     */
    public function is_user_can_vote_item()
    {
        try
        {
            $this->_vote_access_checks();
            $this->_can_vote_today();
        }
        catch (Exception $e)
        {
            $this->error_msg = $e->getMessage();
            return false;
        }
        return true;
    }

    /**
     *  Check is need show buttons for target
     *
     * @return bool
     */
    protected function _is_vote_button_hide()
    {
        try
        {
            $this->_vote_access_checks();
        }
        catch (Exception $e)
        {
            return true;
        }
        return false;
    }

    /**
     *  Use vbulletin native system, for throwing error
     *
     * @return bool
     */
    public function throw_error()
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
     * Voting
     *
     * @param int $user_id
     * @param int $vote_type
     * @return bool
     */
    public function add_user_vote($user_id, $vote_type)
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
                    ' . $this->_target_id . ',
                    "' . $this->_content_type_id . '",
                    "' . $vote_type . '",
                    ' . $user_id . ',
                    ' . $this->_target['userid'] . ',
                    ' . TIMENOW .
            ')';
        $this->registry->db->query_write($sql);
        return true;
    }

    /**
     *  Remove vote
     *
     * @param int $user_id
     * @return bool
     */
    public function remove_user_vote($user_id)
    {
        $sql = 'DELETE FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `targetid` = ' . $this->_target_id . '  AND
                    `contenttypeid` = "' . $this->_content_type_id . '" AND
                    `fromuserid` = ' . $user_id;


        $this->registry->db->query_write($sql);
        return true;
    }

    /**
     *  Clear votes for target
     *
     * @param int $vote_type
     * @return bool
     */
    public function remove_all_user_votes($vote_type)
    {
        $sql = 'DELETE FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `targetid` = ' . $this->_target_id . '  AND
                    `contenttypeid` = "' . $this->_content_type_id . '" AND
                    `vote` = "' . $vote_type . '"';

        $this->registry->db->query_write($sql);
        return true;
    }

    /**
     * Get votes counn all or by vote type
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
                    pv.`targetid` = ' . $this->_target_id . ' AND pv.`contenttypeid` = "' . $this->_content_type_id . '" ' . $vote_type_condition;
        $query_result = $this->registry->db->query_first($sql);
        return $query_result['vote_cont'];
    }

}

/**
 * Null object for non-acceptied voting type
 */
class Votes_Null
{

    /**
     * Overloading all methods
     *
     * @global vb_Registry  $vbulletin
     * @param string        $name
     * @param array         $arguments
     * @return mixed        bool|string
     */
    public function __call($name, $arguments)
    {
        switch ($name)
        {
            case 'throw_error':
                global $vbulletin;
                standard_error(fetch_error('vbv_unsupported_type', $vbulletin->options['contactuslink']));
            case 'render_vote_result_bit':
            case 'render_vote_buttons':
                return '';
            default:
                return false;
        }
    }

}

/**
 * Vote for vBForum Post
 */
class Votes_vBForum_Post extends Votes
{

    public function set_target_id()
    {
        $this->_target_id = $this->_target['postid'];
    }

    /**
     * Can user vote. All checks.
     * Override parent method.
     *
     * @return bool
     */
    protected function _vote_access_checks()
    {
        parent::_vote_access_checks();
        $this->_is_forum_closed();
        return true;
    }

    /**
     *  Check forum status
     *
     * @return bool
     */
    protected function _is_forum_closed()
    {
        $threadinfo = fetch_threadinfo($this->_target['threadid']);
        $ignored_forums = explode(',', $this->registry->options['vbv_ignored_forums']);
        if ($this->registry->options['vbv_ignore_forum_childs'])
        {
            $foruminfo = fetch_foruminfo($threadinfo['forumid']);
            foreach($ignored_forums as $ignored_forum_id)
            {
                if (in_array(intval($ignored_forum_id), explode(',', $foruminfo['parentlist'])))
                {
                    throw new Exception('vbv_post_can_not_be_voted');
                }
            }
        }
        else
        {
            if (in_array($threadinfo['forumid'], $ignored_forums))
            {
                throw new Exception('vbv_post_can_not_be_voted');
            }
        }
        return false;
    }

}
?>
