{**
 * HiPay Enterprise SDK Prestashop
 *
 * 2017 HiPay
 *
 * NOTICE OF LICENSE
 *
 * @author    HiPay <support.tpp@hipay.com>
 * @copyright 2017 HiPay
 * @license   https://github.com/hipay/hipay-enterprise-sdk-prestashop/blob/master/LICENSE.md
 *}

{if $localPaymentName eq "applepay"}
    {include file="$hipay_enterprise_tpl_dir/front/formFieldTemplate/$psVersion/inputApplePay.tpl"}
{elseif !$forceHpayment}
    <div id="hipay-container-hosted-fields-{$localPaymentName}"></div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var activatedLocalError = "{l s="This payment mean is unavailable or the order currency is not supported. Please choose an other payment method." mod="hipay_enterprise"}";
            {if $confHipay.account.global.sandbox_mode}
                var api_tokenjs_mode = "stage";
                var api_tokenjs_username = "{$confHipay.account.sandbox.api_tokenjs_username_sandbox}";
                var api_tokenjs_password_publickey = "{$confHipay.account.sandbox.api_tokenjs_password_publickey_sandbox}";
            {else}
                var api_tokenjs_mode = "production";
                var api_tokenjs_username = "{$confHipay.account.production.api_tokenjs_username_production}";
                var api_tokenjs_password_publickey = "{$confHipay.account.production.api_tokenjs_password_publickey_production}";
            {/if}

            var container = "hipay-container-hosted-fields-{$localPaymentName}";
            var options = {
                selector: container,
                template: "auto"
            };

            var localHipay = new HiPay({
                username: api_tokenjs_username,
                password: api_tokenjs_password_publickey,
                environment: api_tokenjs_mode,
                lang: "{$languageIsoCode}"
            });

            var localHF = localHipay.create("{$localPaymentName}", options);
            var extraFields = [];

            // Create input for each additionnal Hosted Field
            setTimeout(function() {
                $("[data-hipay-id^=hipay-{$localPaymentName}-field-row-]")
                .each(function(i, el) {
                    var field = $(el).data("hipay-id").split("row-")[1];
                    extraFields.push(field);

                    $("#{$localPaymentName}-hipay")
                    .append('<input id="{$localPaymentName}-' + field + '" type="hidden" name="HF-' + field + '" />');
                });
            }, 1000);

            $("#{$localPaymentName}-hipay").submit(function(e) 
            {
                var form = this;
                e.preventDefault();
                e.stopPropagation();

                localHF.getPaymentData().then(
                    function(response) {
                        // Hide formular and show loader
                        $("#{$localPaymentName}-hipay").hide();
                        $("#{$localPaymentName}-payment-loader-hp").show();
                        $("#payment-confirmation > .ps-shown-by-js > button").prop("disabled", true);

                        // Fill hidden fields to send to server
                        $("#{$localPaymentName}-browserInfo").val(JSON.stringify(response.browser_info));
                        extraFields.forEach(function(field) {
                            $("#{$localPaymentName}-" + field).val(response[field]);
                        });

                        form.submit();
                        return true;
                    },
                    function(errors) {
                        $("#error-js").show();
                        $("#error-js").text(activatedLocalError);
                        return false;
                    }
                );
            });
        });
    </script>
    <input type="hidden" name="localSubmit" />
    <input class="ioBB" type="hidden" name="ioBB" />
    <input id="{$localPaymentName}-browserInfo" type="hidden" name="HF-browserInfo" />
{else}
    {if $iframe}
        <p>{l s="Confirm your order to go to the payment page" mod="hipay_enterprise"}</p>
    {else}
        <p>{l s="You will be redirected to an external payment page. Please do not refresh the page during the process" mod="hipay_enterprise"}
        </p>
    {/if}
{/if}