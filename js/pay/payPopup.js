/*
 * Copyright (c) 2019 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 * 
 * Released under the GNU General Public License
 */
function createPayOrder(baseurl) {
    jQuery(".btn-checkout").prop('disabled', true);
    jQuery.ajax({
        url: baseurl + "pay/processing/redirect",
        beforeSend: function(xhr) {
            xhr.overrideMimeType("text/plain; charset=x-user-defined");
        }
    }).done(function(data) {
        var params = JSON.parse(data);
        jQuery(".btn-checkout").after("<div id='payPopup'></div>");
        jQuery("#payPopup").append("<div id='payPopupContent'></div>");
        jQuery("#payPopupContent").append("<form target='myIframe' name='paygate_checkout' id='paygate_checkout' action='https://secure.paygate.co.za/payweb3/process.trans' method='post'><input type='hidden' name='PAY_REQUEST_ID' value='" + params['PAY_REQUEST_ID'] + "' size='200'><input type='hidden' name='CHECKSUM' value='" + params['CHECKSUM'] + "' size='200'></form><iframe id='payPopupFrame' name='myIframe'  src='#' width='750' height='620'></iframe><script type='text/javascript'>document.getElementById('paygate_checkout').submit();</script>");
    });
}