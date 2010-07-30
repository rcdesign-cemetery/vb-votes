
/**
 * Adds onclick events to voting buttons and delete links
 *
 * @param	string	The ID of the form that contains the rating options
 * @param   int     Post id [optional]
 */
function AJAX_PostVote_Init(posts_container, post_id){
    if(AJAX_Compatible){
        if(typeof posts_container=="string"){
            posts_container = fetch_object(posts_container)
        }
        if (typeof post_id != "undefined")
        {
            PostVoteBit_Init(post_id);
            return true;
        }
        var post_id;
        if ('li' == posts_container.tagName)
        {
            post_id = post_elements[i].id.substr(5);
            PostVoteBit_Init(post_id);
        }
        else
        {
            var post_elements = fetch_tags(posts_container, "li");
            for(var i=0; i<post_elements.length; i++){
                // vote buttons

                if(post_elements[i].id && post_elements[i].id.indexOf("post_") == 0){
                    post_id = post_elements[i].id.substr(5);
                    PostVoteBit_Init(post_id);
                }

            }
        }
    }
    return true;
}

PostVoteBit_Init = function (post_id)
{
    button_link = YAHOO.util.Dom.get('PostVote::Positive::' + post_id);
    if (button_link)
    {
        button_link.onclick = AJAX_PostVote.prototype.vote_click;
    }
    button_link = YAHOO.util.Dom.get('PostVote::Negative::' + post_id);
    if (button_link)
    {
        button_link.onclick = AJAX_PostVote.prototype.vote_click;
    }
    var patterns = new Array(
        'RemoveVote::All::Positive::',
        'RemoveVote::Positive::',
        'RemoveVote::All::Negative::',
        'RemoveVote::Negative::'
    );
    for(var i=0; i<patterns.length; i++)
    {
        remove_link = YAHOO.util.Dom.get(patterns[i] + post_id);
        if (remove_link)
        {
            remove_link.onclick = AJAX_PostVote.prototype.remove_vote_click;
        }
    }

    PostVoteBit_results_show(post_id)
}

PostVoteBit_results_show = function(post_id)
{
    var current_result_block = false;
    var post_elem = YAHOO.util.Dom.get('post_' + post_id);
    if (post_elem.getAttribute("class").indexOf("ignore") == -1)
    {
        current_result_block = YAHOO.util.Dom.get('Positive_votes_post_' + post_id);
        if (current_result_block)
        {
            if (current_result_block.childNodes.length > 1)
            {
                current_result_block.style.display = '';
            }
        }
        current_result_block = YAHOO.util.Dom.get('Negative_votes_post_' + post_id);
        if (current_result_block)
        {
            if (current_result_block.childNodes.length > 1)
            {
                current_result_block.style.display = '';
            }
        }
    }
}

/**
 * Class to handle thread rating
 *
 * @param	object	The form object containing the vote options
 */
function AJAX_PostVote(post_id, action_name)
{
    this.post_id = post_id;

    this.url = "vb_votes.php?do=" + action_name;

    // vB_Hidden_Form object to handle form variables
    this.pseudoform = new vB_Hidden_Form('vb_votes.php');
    this.pseudoform.add_variable('ajax', 1);
    this.pseudoform.add_variable('s', fetch_sessionhash());
    this.pseudoform.add_variable('securitytoken', SECURITYTOKEN);
    this.pseudoform.add_variable('targetid', post_id);
    // const VOTE_CONTENT_TYPE, defined in templates
    this.pseudoform.add_variable('contenttype', VOTE_CONTENT_TYPE);

    // Output object
    this.output_element_id = 'voted_' + post_id;

}

/**
 * Add parameter to request pseudo form
 *
 * @param	string	name
 * @param	string	value
 */
AJAX_PostVote.prototype.add_variable_to_request = function (name, value)
{
    this.pseudoform.add_variable(name, value);
}

/**
 * Handler function for vote buttons
 *
 */
AJAX_PostVote.prototype.vote_click = function()
{
    var post_id = this.name.substr(this.name.lastIndexOf("::")+2);
    var post_vote = new AJAX_PostVote(post_id, 'vote');

    var vote_value = "-1";
    if(this.name.indexOf("Positive::")!=-1){
        vote_value = "1";
    }
    post_vote.add_variable_to_request('value', vote_value);

    post_vote.send_request();
    return false;
    
}

/**
 * Handler function for remove vote(s) links
 */
AJAX_PostVote.prototype.remove_vote_click = function()
{

    var post_id = this.name.substr(this.name.lastIndexOf("::")+2);
    var post_vote = new AJAX_PostVote(post_id, 'remove');

    if(this.name.indexOf("All::")!=-1){
        // we need type of voite only for mass remove
        var vote_value = "-1";
        if(this.name.indexOf("Positive::")!=-1){
            vote_value = "1";
        }
        post_vote.add_variable_to_request('value', vote_value);
        post_vote.add_variable_to_request('all', '1');
    }

    post_vote.send_request();

    return false;
}

/**
 * Send ajax request to vote.php
 */
AJAX_PostVote.prototype.send_request = function()
{

    YAHOO.util.Connect.asyncRequest("POST", this.url, {
        success: this.handle_ajax_response,
        failure: vBulletin_AJAX_Error_Handler,
        timeout: vB_Default_Timeout,
        scope: this
    }, this.pseudoform.build_query_string());
    
    return false;
}

/**
 * Handler for ajax response
 */
AJAX_PostVote.prototype.handle_ajax_response = function(ajax)
{
    if (ajax.responseXML)
    {
        // check for error first
        var error = ajax.responseXML.getElementsByTagName('error');
        if (error.length)
        {
            alert(error[0].firstChild.nodeValue)
        }
        else
        {

            var positive_votes = ajax.responseXML.getElementsByTagName('positive_votes');
            this.update_votes_result('Positive', positive_votes);

            var negative_votes = ajax.responseXML.getElementsByTagName('negative_votes');
            this.update_votes_result('Negative', negative_votes);

            // enable/disable vote buttons
            var vote_button_style = '';
            var vote_button_style_response = ajax.responseXML.getElementsByTagName('vote_button_style');
            if (vote_button_style_response)
            {
                if (vote_button_style_response[0].hasChildNodes())
                {
                    vote_button_style = vote_button_style_response[0].firstChild.nodeValue;
                }
            }
            this.handle_vote_buttons(vote_button_style);

            PostVoteBit_Init(this.post_id)
        }
    }
}


AJAX_PostVote.prototype.update_votes_result = function(vote_type, vote_result)
{
    if (vote_result)
    {
        var current_result_block = YAHOO.util.Dom.get(vote_type + '_votes_post_' + this.post_id);
        var votes_result = string_to_node(vote_result[0].firstChild.nodeValue);
        current_result_block.parentNode.replaceChild(votes_result, current_result_block);
    }
}

/**
 * Hide/show vote buttons (with separators)
 * ToDo refactor
 */
AJAX_PostVote.prototype.handle_vote_buttons = function(vote_button_style)
{
    // buttons
    this.set_button_style("PostVote::Positive::", vote_button_style);
    this.set_button_style("PostVote::Negative::", vote_button_style);

    // separators
    this.set_button_style("vote_pos_sep_", vote_button_style);
    this.set_button_style("vote_neg_sep_", vote_button_style);
}

AJAX_PostVote.prototype.set_button_style = function(prefix, vote_button_style)
{
    var elem = YAHOO.util.Dom.get(prefix+this.post_id);
    if (elem)
    {
        elem.style.display = vote_button_style;
    }
}
