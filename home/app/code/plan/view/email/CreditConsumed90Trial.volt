<html>

<head>
    <meta charset="utf-8" />
    <meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Urgent: Action Required to Continue Order Syncing </title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">
    <style type="text/css">
        @import url('https://fonts.googleapis.com/css2?family=Roboto&display=swap');

        html,
        body {
            margin: 0 auto !important;
            padding: 0 !important;
            width: 100% !important;
            font-family: 'Roboto', sans-serif;
        }

        img+div {
            display: none;
        }

        table {
            border-spacing: 0 !important;
            border-collapse: collapse !important;
            margin: 0 auto !important;
        }

        /* Media Queries */
        @media screen and (max-width: 640px) {
            table[class="email-container"] {
                width: 100% !important;
            }

            .heading {
                font-size: 16px !important;
                line-height: 24px !important;
            }

            .mob-paddding {
                padding: 12px 15px !important;
            }
        }

        @media screen and (max-width: 500px) {
            table[class="email-container"] {
                width: 100% !important;
            }
        }
    </style>
</head>

<body width="100%" style="margin: 0; background-color: #fff;">
    <table cellspacing="0" cellpadding="0" width="100%" style="margin-left: auto; margin-right: auto">
        <tr>
            <td width="100%">
                <table class="email-container" max-width="100%" cellspacing="0" cellpadding="0" width="600"
                    style="margin-left:auto; margin-right: auto;background-color: #eeeeee;">
                    <tr>
                        <td width="100%" class="mob-paddding"
                            style="padding-left: 20px;padding-right: 20px;background: #F6F6F7;">
                            <!-- email body starts -->
                            <table cellspacing=" 0" cellpadding="0" width="100%" height="100%"
                                style="margin-left: auto; margin-right: auto; background-color: #F6F6F7; border: 0">
                                <tr>
                                    <td width="100%">
                                        <table cellspacing="0" cellpadding="0"
                                            style="background-color: #F6F6F7; width: 100%;">
                                            <tr>
                                                <td style="text-align: right; padding-top: 34px;" width="35%">
                                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png" style="width: 100%;max-width: 24px;">
                                                </td>
                                                <td width="65%"
                                                    style="font-size: 16px;line-height: 20px;color: #202223;font-weight: 400; padding-top: 34px;   padding-left: 12px; text-align: left;"
                                                    class="heading">
                                                    {{ app_name }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px;  color: #E1E3E5; padding-top: 14px; padding-bottom: 40px;"
                                        class="line-break">
                                        <span style="display: block; border-top: 1px solid #ece9ff;"></span>
                                    </td>
                                </tr>
                            </table>
                            <!-- email header ends -->
                            <!-- email content starts -->
                            <table cellspacing="0" cellpadding="0" width="100%"
                                style="border: 0; background-color: #f6f6f7;">
                                <tr>
                                    <td width="100%">
                                        <table style="background-color: #F6F6F7; margin-left: auto; margin-right: auto;"
                                            width="100%" class="import">

                                            <tr>
                                                <td width="100%" style="text-align: center;">
                                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/limit90.png" style="width: 100%; max-width: 250px;">
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="100%"
                                                    style="font-size:26px;line-height:32px;padding-top: 12px;padding-bottom: 20px; color: #333333;text-align: center">
                                                    Urgent: Action Required to Continue Order Syncing </td>
                                            </tr>

                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333; background-color: #fff; padding:32px 32px 16px;border-radius: 8px 8px 0 0;"
                                        class="mob-paddding">
                                        <span
                                            style="display:block;padding-bottom: 30px;text-align: left;font-weight: 600;">Hello
                                            {{ name }}, </span>
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;"> We hope you've been enjoying the benefits of our {{ app_name }} app during your free trial.
                                        </span>
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;"> We wanted to give you a heads-up that you've already used 90% of your 50 free order syncs. This means you're very close to reaching your order limit.
                                        </span>
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;margin-top: 10px;"> Your current subscription plan - <b>({{
                                                plan_details['title'] }} - ${{ plan_details['custom_price'] }}
                                                with {{
                                                plan_details['description'] }})</b>. </span>
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;margin-top: 10px;">Once your free trial ends and your order credits are exhausted, you won't be able to sync any more orders from Amazon to Shopify. To avoid any disruptions to your business, we strongly recommend upgrading to a paid plan.
                                        </span>
                                    </td>
                                </tr>
                            </table>
                           
                            <table cellspacing="0" cellpadding="0" width="100%"
                                style="border: 0; background-color: #F6F6F7;">
                                {% if has_recommended_plan_link %}
                                <tr>
                                    <td width="100%" style="background-color: #fff; padding: 0 32px 16px;"
                                        class="mob-paddding">
                                        <table width="100%" style="background-color: #F1F8F5; border-radius: 8px;">

                                            <tr>
                                                <td width="100%" style="padding: 20px 20px 0;">
                                                    <span
                                                        style="font-weight: 600; font-size: 16px; line-height: 24px; margin-bottom: 4px; color: #202223; display: block;">Upgrade
                                                        Plan</span>
                                                        {% if postpaid_enabled %}
                                                        <span
                                                        style="font-weight: 400; font-size: 14px; line-height: 20px; color: #202223;">Avoid
                                                        Excess Usage Charge by upgrading to a more suitable plan. This
                                                        offer is valid till no Excess Usage Charge amount is
                                                        generated.</span>
                                                        {% else %}
                                                        <span
                                                        style="font-weight: 400; font-size: 14px; line-height: 20px; color: #202223;">You can upgrade your plan immediately to enjoy
                                                        uninterrupted service. We recommend the following
                                                        plans as per your order credit usage - </span>
                                                        {% endif %}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="100%" style="padding: 20px;">
                                                    <table width="100%"
                                                        style="border-radius: 8px; background-color: #fff;">
                                                        <tr>
                                                            <td width="100%" colspan="2" style="padding: 16px 16px 0;">
                                                                <span
                                                                    style="font-weight: 600; font-size: 16px; line-height: 24px; margin-right: 8px; color: #202223;">
                                                                    <b>{{ recommended_plan_name }}</b>
                                                                </span>
                                                                <span
                                                                    style="font-weight: 400; font-size: 12px; line-height: 16px; color: #202223; background: #FFEA8A; border-radius: 3px; padding: 2px 8px;">Recommended</span>
                                                            </td>
                                                        </tr> {% for plan in recommended_plan %} {% if
                                                        plan['plan_data']['billed_type']
                                                        == 'monthly' %} <tr>
                                                            <td style="padding: 18px  0 18px 16px;">
                                                                <span
                                                                    style="font-weight: 600; font-size: 14px; line-height: 20px; display:block; color: #202223; margin-bottom: 4px;">$
                                                                    {{ plan['plan_data']['custom_price'] }}/mo
                                                                </span>
                                                                <span
                                                                    style="font-weight: 400; font-size: 14px; line-height: 20px; display:block; color: #202223">Manage
                                                                    {{ plan['plan_data']['description'] }} per
                                                                    month</span>
                                                            </td>
                                                            <td style="padding-right: 16px; text-align: right;">
                                                                <button type="button"
                                                                    style="background: #008060;border: 1px solid #008060; box-shadow: 0px 1px 0px rgba(0, 0, 0, 0.05); border-radius: 4px; padding: 8px 16px;">
                                                                    <a href="{{plan['link']}}"
                                                                        style="font-weight: 500; font-size: 14px; line-height: 20px;text-decoration: none; color:  #fff;">Pay
                                                                        {{plan['plan_data']['billed_type']}}</a>
                                                                </button>
                                                            </td>
                                                        </tr> {% endif %} {% if plan['plan_data']['billed_type']
                                                        ==
                                                        'yearly'
                                                        %}
                                                        <tr>
                                                            <td width="100" colspan="2" style="padding:0 16px">
                                                                <hr style="margin:0;border-top:1px solid #e1e3e5">
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding: 18px  0 18px 16px;"> {% if
                                                                plan['plan_data']['montly_price'] !=
                                                                plan['plan_data']['monthly_discounted_price'] %}
                                                                <span
                                                                    style="font-weight: 400; font-size: 14px; line-height: 20px; color: #6D7175; margin-bottom: 4px; text-decoration: line-through;">$
                                                                    {{ plan['plan_data']['montly_price']
                                                                    }}</span> {%
                                                                endif
                                                                %} <span
                                                                    style="font-weight: 600; font-size: 14px; line-height: 20px; color: #202223; margin-bottom: 4px;">$
                                                                    {{
                                                                    plan['plan_data']['monthly_discounted_price']
                                                                    }}/mo</span>
                                                                <span
                                                                    style="font-weight: 700; font-size: 14px; line-height: 20px; display:block; color: #347C84; margin: 4px 0;">(Billed
                                                                    at ${{ plan['plan_data']['discounted_price']
                                                                    }} per year )</span>
                                                                <span
                                                                    style="font-weight: 400; font-size: 14px; line-height: 20px; display:block; color: #202223">Manage
                                                                    {{ plan['plan_data']['description'] }} per
                                                                    month</span>
                                                            </td>
                                                            <td style="padding-right: 16px; text-align: right;">
                                                                <button type="button"
                                                                    style="background: #008060;border: 1px solid #008060; box-shadow: 0px 1px 0px rgba(0, 0, 0, 0.05); border-radius: 4px; padding: 8px 16px;">
                                                                    <a href="{{plan['link']}}"
                                                                        style="font-weight: 500; font-size: 14px; line-height: 20px;text-decoration: none; color:  #fff;">
                                                                        Pay
                                                                        {{plan['plan_data']['billed_type']}} </a>
                                                                </button>
                                                            </td>
                                                        </tr> {% endif %} {% endfor %}
                                                    </table>
                                                </td>
                                            </tr>

                                        </table>
                                    </td>
                                </tr>
                                {% else %}
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333; background-color: #fff; padding:32px 32px 16px;border-radius: 8px 8px 0 0;"
                                        class="mob-paddding">
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;"> We offer a variety of plans to suit different business needs. {% if page_link %} You can view our pricing plans and upgrade here:  <a href="{{ page_link }}"
                                            color="#2C6ECB">here</a>. 
 {% endif%}
                                        </span>
                                    </td>
                                </tr>
                                {% endif %}
                            </table>
                            
                            <table cellspacing="0" cellpadding="0" width="100%"
                                style="border: 0; background-color: #F6F6F7;">
                                {% if postpaid_enabled %} <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333;  background-color: #fff; padding: 0px 32px 10px;"
                                        class="mob-paddding">
                                        <b>Note:</b>
                                        <i>If the order credits have reached the limit, kindly upgrade your plan, or
                                            you
                                            will be
                                            charged
                                            $3
                                            for every 10 orders, and these Excess Usage Charges must be cleared
                                            before the
                                            end
                                            of the
                                            month.
                                        </i>
                                    </td>
                                </tr> {% endif %} <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333;  background-color: #fff; padding: 0px 32px 0px;"
                                        class="mob-paddding"> 
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                        color: #333333;margin-top: 10px;">Don't miss out on the benefits of seamless order syncing. Upgrade your plan today to continue enjoying uninterrupted service.
                                    </span>
                                    <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                        color: #333333;margin-top: 10px;">If you have any questions or need assistance, please don't hesitate to contact our support team at <a href="{{ support_page }}"
                                        color="#2C6ECB">here</a>.
                                    </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333; background-color: #fff; border-radius: 0px 0px 8px 8px; padding: 0px 32PX 32px;"
                                        class="mob-paddding">
                                        <br /> Thanks, <br />
                                        <span style="font-weight: 600;">Team CedCommerce</span>
                                        <br />
                                        <br />
                                    </td>
                                </tr>

                            </table>
                            <!-- email footer starts -->
                            <table cellspacing="0" cellpadding="0" width="100%"
                                style="background-color: #F6F6F7; border: 0;">

                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px;  color: #E1E3E5; padding-top: 20px; padding-bottom: 20px;"
                                        class="mob-paddding">
                                        <span style="display: block; border-top: 1px solid #ece9ff;"></span>
                                    </td>
                                </tr>

                            </table>
                            <table cellspacing="0" cellpadding="0" width="100%" class=" pt-0"
                                style="border: 0; background-color: #F6F6F7; margin-left: auto; margin-right: auto;">

                                <tr>
                                    <td width="100%" style="font-size: 14px; text-align: center; color: #616771;">
                                        <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png" style="width: 100%;max-width: 24px;">
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="color:#262626;line-height:17px;padding-top:16px;text-align:center">
                                        Team
                                        CedCommerce
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="color:#262626;line-height:17px;padding-top:8px;text-align:center;font-size: 10px;max-width: 230px;">
                                        CedCommerce Inc. 1B12 N Columbia Blvd Suite C15-653026 Portland, Oregon,
                                        97217, USA
                                    </td>
                                </tr>
                                {% if has_unsubscribe_link %}
                                <tr>
                                    <td width="100%"
                                        style="color:#262626;line-height:17px;padding-top:8px;text-align:center;font-size: 10px;max-width: 230px;">
                                        <a href="{{ unsubscribe_link }}">Unsubscribe</a>
                                    </td>
                                </tr>
                                {% endif %}
                                <tr>
                                    <td width="100%"
                                        style="color:#262626;line-height:17px;padding-top:10px;padding-bottom:48px;text-align:center;">
                                        <a href="https://www.facebook.com/CedCommerce/" title="fb"
                                            style="text-decoration: none;">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/fb.png" />
                                        </a>
                                        <a href="https://twitter.com/cedcommerce/" title="twitter"
                                            style="padding-left:20px; text-decoration: none;">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/x.png" />
                                        </a>
                                        <a href="https://www.instagram.com/CedCommerce/" title="social-insta"
                                            style="padding-left:20px; text-decoration: none;">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/insta.png" />
                                        </a>
                                        <a href="https://www.linkedin.com/company/cedcommerce" title="linkedin"
                                            style="padding-left: 20px; text-decoration: none;">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/linkedin.png" />
                                        </a>
                                        <a href="https://www.youtube.com/channel/UCLRUCC_jvKf4tfZ2omjaW8Q"
                                            title="youtube" style="padding-left: 20px; text-decoration: none;">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/yt.png" />
                                        </a>
                                    </td>
                                </tr>

                            </table>
                            <!-- email footer ends -->
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>