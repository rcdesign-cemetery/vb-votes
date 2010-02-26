
/**
 * Adds onclick events to appropriate elements for thread rating
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

AJAX_PostVote.prototype.add_variable_to_request = function (name, value)
{
    this.pseudoform.add_variable(name, value);
}

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
            var votes_div = YAHOO.util.Dom.get("votes_result_"+this.post_id);
            if (! votes_div)
            {
                var post_div = YAHOO.util.Dom.get("post"+this.post_id);
                if (! post_div)
                {
                    // todo add error handle
                    return false;
                }
                var votes_div = document.createElement('div');
                votes_div.id = "votes_result_"+this.post_id;
                post_div.appendChild(votes_div);
            }
            votes_div.innerHTML = votes_html;
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
            AJAX_PostVote_Init('post' + this.post_id);
            AJAX_PostVote_Init('votes_result_' + this.post_id);
        }
    }
}

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
