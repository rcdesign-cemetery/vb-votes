
/**
 * Adds onclick events to voting buttons and delete links
 *
 * @param   string  The ID of the form that contains the rating options
 * @param   int     item id [optional]
 */
function AJAX_ItemVote_Init(items_container, item_id_name, item_id){
    if(AJAX_Compatible){
        if(typeof items_container=="string"){
            items_container = fetch_object(items_container)
        }
        // safety check. Make sure object is in DOM (could be false for social groups)
        if (!items_container)
        {
            return false;
        }
        if (typeof item_id != "undefined")
        {
            ItemVoteBit_Init(item_id_name, item_id);
            return true;
        }
        var item_id;
        if ('li' == items_container.tagName)
        {
            var index = item_elements[i].id.indexOf('_');
            if (index > 0) {
                item_id = item_elements[i].id.substr(index+1);
                ItemVoteBit_Init(item_id_name, item_id);
            }
        }
        else
        {
            var item_elements = fetch_tags(items_container, "li");
            for(var i=0; i<item_elements.length; i++){
                // vote buttons

                if(item_elements[i].id && item_elements[i].id.indexOf(item_id_name) == 0){
                    var index = item_elements[i].id.indexOf('_');
                    if (index > 0) {
                        item_id = item_elements[i].id.substr(index+1);
                        ItemVoteBit_Init(item_id_name, item_id);
                    }
                }

            }
        }
    }
    return true;
}

ItemVoteBit_Init = function (item_id_name, item_id)
{
    button_link = YAHOO.util.Dom.get('PostVote::Positive::' + item_id);
    if (button_link)
    {
        button_link.onclick = AJAX_ItemVote.prototype.vote_click;
    }
    button_link = YAHOO.util.Dom.get('PostVote::Negative::' + item_id);
    if (button_link)
    {
        button_link.onclick = AJAX_ItemVote.prototype.vote_click;
    }
    var patterns = new Array(
        'RemoveVote::All::Positive::',
        'RemoveVote::Positive::',
        'RemoveVote::All::Negative::',
        'RemoveVote::Negative::'
    );
    for(var i=0; i<patterns.length; i++)
    {
        remove_link = YAHOO.util.Dom.get(patterns[i] + item_id);
        if (remove_link)
        {
            remove_link.onclick = AJAX_ItemVote.prototype.remove_vote_click;
        }
    }

    ItemVoteBit_results_show(item_id_name, item_id)
}

ItemVoteBit_results_show = function(item_id_name, item_id)
{
    var current_result_block = false;
    var item_elem = YAHOO.util.Dom.get(item_id_name + item_id);
    if (item_elem.className.indexOf("ignore") == -1)
    {
        current_result_block = YAHOO.util.Dom.get('Vote_message_' + item_id);
        if (current_result_block)
        {
            if (current_result_block.childNodes.length > 0)
            {
                current_result_block.style.display = '';
            }
        }
    }
}

/**
 * Class to handle thread rating
 *
 * @param   object  The form object containing the vote options
 */
function AJAX_ItemVote(item_id, action_name)
{
    this.item_id = item_id;

    this.url = "vb_votes.php?do=" + action_name;

    // vB_Hidden_Form object to handle form variables
    this.pseudoform = new vB_Hidden_Form('vb_votes.php');
    this.pseudoform.add_variable('ajax', 1);
    this.pseudoform.add_variable('s', fetch_sessionhash());
    this.pseudoform.add_variable('securitytoken', SECURITYTOKEN);
    this.pseudoform.add_variable('targetid', item_id);
    // const VOTE_CONTENT_TYPE, defined in templates
    this.pseudoform.add_variable('contenttype', VOTE_CONTENT_TYPE);

    // Output object
    this.output_element_id = 'voted_' + item_id;

}

/**
 * Add parameter to request pseudo form
 *
 * @param   string  name
 * @param   string  value
 */
AJAX_ItemVote.prototype.add_variable_to_request = function (name, value)
{
    this.pseudoform.add_variable(name, value);
}

/**
 * Handler function for vote buttons
 *
 */
AJAX_ItemVote.prototype.vote_click = function()
{
    var item_id = this.name.substr(this.name.lastIndexOf("::")+2);
    var item_vote = new AJAX_ItemVote(item_id, 'vote');

    var vote_value = "-1";
    if(this.name.indexOf("Positive::")!=-1){
        vote_value = "1";
    }
    item_vote.add_variable_to_request('value', vote_value);

    item_vote.send_request();
    return false;
    
}

/**
 * Handler function for remove vote(s) links
 */
AJAX_ItemVote.prototype.remove_vote_click = function()
{

    var item_id = this.name.substr(this.name.lastIndexOf("::")+2);
    var item_vote = new AJAX_ItemVote(item_id, 'remove');

    if(this.name.indexOf("All::")!=-1){
        // we need type of vote only for mass remove
        var vote_value = "-1";
        if(this.name.indexOf("Positive::")!=-1){
            vote_value = "1";
        }
        item_vote.add_variable_to_request('value', vote_value);
        item_vote.add_variable_to_request('all', '1');
    }

    item_vote.send_request();

    return false;
}

/**
 * Send ajax request to vote.php
 */
AJAX_ItemVote.prototype.send_request = function()
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
AJAX_ItemVote.prototype.handle_ajax_response = function(ajax)
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

            var votes = ajax.responseXML.getElementsByTagName('votes');
            this.update_votes_result(votes);

            // enable/disable vote buttons
            var vote_button_style = '';
            var vote_button_style_response = ajax.responseXML.getElementsByTagName('vote_buttons_visibility');
            if (vote_button_style_response)
            {
                if (vote_button_style_response[0].hasChildNodes())
                {
                    vote_button_style = vote_button_style_response[0].firstChild.nodeValue;
                }
            }
            this.handle_vote_buttons(vote_button_style);

            var item_id_name_response = ajax.responseXML.getElementsByTagName('item_id_name');
            var item_id_name= '';
            if (item_id_name_response && item_id_name_response[0].hasChildNodes())
            {
                item_id_name = item_id_name_response[0].firstChild.nodeValue;
            }

            ItemVoteBit_Init(item_id_name, this.item_id)
        }
    }
}


AJAX_ItemVote.prototype.update_votes_result = function(vote_result)
{
    if (vote_result)
    {
        var current_result_block = YAHOO.util.Dom.get('Vote_message_' + this.item_id);
        var votes_result = string_to_node(vote_result[0].firstChild.nodeValue);
        current_result_block.parentNode.replaceChild(votes_result, current_result_block);
    }
}

/**
 * Hide/show vote buttons (with separators)
 * ToDo refactor
 */
AJAX_ItemVote.prototype.handle_vote_buttons = function(vote_button_style)
{
    //sg buttons container
    if (!this.set_button_style("vote-buttons-container-", vote_button_style))
    {
        // buttons
        this.set_button_style("PostVote::Positive::", vote_button_style);
        this.set_button_style("PostVote::Negative::", vote_button_style);

        // separators
        this.set_button_style("vote_pos_sep_", vote_button_style);
        this.set_button_style("vote_neg_sep_", vote_button_style);
    }
}

AJAX_ItemVote.prototype.set_button_style = function(prefix, vote_button_style)
{
    var elem = YAHOO.util.Dom.get(prefix+this.item_id);
    if (elem)
    {
        elem.style.display = vote_button_style;
        return true;
    }
    return false;
}
