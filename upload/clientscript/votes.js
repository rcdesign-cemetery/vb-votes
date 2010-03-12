
/**
 * Adds onclick events to voting buttons and delete links
 *
 * @param	string	The ID of the form that contains the rating options
 */
function AJAX_PostVote_Init(posts_list){
    if(AJAX_Compatible){
        if(typeof posts_list=="string"){
            posts_list = fetch_object(posts_list)
        }
        var post_elements = fetch_tags(posts_list, "a");
        for(var i=0; i<post_elements.length; i++){
            // vote buttons
            if(post_elements[i].name && post_elements[i].name.indexOf("PostVote::")!=-1){
                post_elements[i].onclick = AJAX_PostVote.prototype.vote_click;
            }

            // "remove vote" links
            if(post_elements[i].name && post_elements[i].name.indexOf("RemoveVote::")!=-1){
                post_elements[i].onclick = AJAX_PostVote.prototype.remove_vote_click;
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

    this.url = "votes.php?do=" + action_name;

    // vB_Hidden_Form object to handle form variables
    this.pseudoform = new vB_Hidden_Form('votes.php');
    this.pseudoform.add_variable('ajax', 1);
    this.pseudoform.add_variable('s', fetch_sessionhash());
    this.pseudoform.add_variable('securitytoken', SECURITYTOKEN);
    this.pseudoform.add_variable('targetid', post_id);

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
            var vote_response = ajax.responseXML.getElementsByTagName('voted_div');
            var votes_html = '';
            if (vote_response[0].hasChildNodes())
            {
                votes_html = vote_response[0].firstChild.nodeValue;
            }
            this.remove_votes_result();
            var edit_div = YAHOO.util.Dom.get('edit' + this.post_id);
            edit_div.innerHTML = edit_div.innerHTML + votes_html;
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
            this.handle_vote_button_div(vote_button_style);
            //AJAX_PostVote_Init('post' + this.post_id);
            AJAX_PostVote_Init('edit' + this.post_id);
        }
    }
}

/**
 * Hide/show vote buttons
 */
AJAX_PostVote.prototype.handle_vote_button_div = function(vote_button_style)
{
    var b_positive = YAHOO.util.Dom.get("PostVote::Positive::"+this.post_id);
    if (b_positive)
    {
        b_positive.style.display = vote_button_style;
    }
    var b_negative = YAHOO.util.Dom.get("PostVote::Negative::"+this.post_id);
    if (b_negative)
    {
        b_negative.style.display = vote_button_style;
    }
}

/**
 * Remove votes results
 * Hotfix for "remove div-container" bug
 * TODO: refactor client and server side
 */
AJAX_PostVote.prototype.remove_votes_result = function()
{
    var edit_div = YAHOO.util.Dom.get('edit' + this.post_id);
    var result_positive = YAHOO.util.Dom.get('PostVotesResult::Positive::' + this.post_id);
    if (result_positive)
    {
        edit_div.removeChild(result_positive);
    }
    var result_negative = YAHOO.util.Dom.get('PostVotesResult::Negative::' + this.post_id);
    if (result_negative)
    {
        edit_div.removeChild(result_negative);
    }
    return true;
}