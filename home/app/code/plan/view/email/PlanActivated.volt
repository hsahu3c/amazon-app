<html>

<head>
    <meta charset="utf-8" />
    <meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Plan Activated</title>
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
                                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/planactivated.png">
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="100%"
                                                    style="font-size:26px;line-height:32px;padding-top: 12px;padding-bottom: 20px; color: #333333;text-align: center">
                                                    Plan Activated Successfully</td>
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
                                            color: #333333;">
                                            <!-- Congratulations! Your {% if plan_details['code'] === 'trial' %} one-time trial{% endif %} plan  for <b>{{ app_name }}</b> has been
                                            successfully activated. You
                                            can now enjoy the seamless integration between Shopify and Amazon. -->
                                            Congratulations! Your plan on the <b>{{ app_name }}</b> has been successfully activated. You can now enjoy seamless integration between your Shopify store and Amazon.
                                        </span>
                                        <span
                                            style="display:block;padding-bottom: 30px;text-align: left;font-weight: 600;"><b>Plan Details:</b> </span>
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;margin-top: 10px;">Activated Plan - <b>{{ plan_details['title'] }} - ${{
                                            plan_details['custom_price'] }} ({{ plan_details['description'] }})</b>
                                        </span>
                                        {% if (is_capped and has_paid_plan) %}
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;margin-top: 10px;">Additional Order Limit  - <b>({{ capped_data['capped_credit'] }} orders at ${{
                                            capped_data['per_unit_usage'] }}/order, capped at ${{ capped_data['capped_amount'] }})</b>
                                        </span>
                                        {% endif %}
                                        
                                    </td>
                                </tr>
                            </table>
                            <table cellspacing="0" cellpadding="0" width="100%"
                                style="border: 0; background-color: #F6F6F7;">
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333;  background-color: #fff; padding: 0px 32px 0px;"
                                        class="mob-paddding"> 
                                        <!-- Thanks again for choosing CedCommerce Amazon Channel. For any queries or further assistance regarding the plan or the additional order limit, please reach out to us  -->
                                        Thank you for choosing the {{ app_name }}.
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333;  background-color: #fff; padding: 0px 32px 0px;"
                                        class="mob-paddding"> 
                                        <!-- Thanks again for choosing CedCommerce Amazon Channel. For any queries or further assistance regarding the plan or the additional order limit, please reach out to us  -->
                                        For further assistance, feel free to contact our support team: <a href="{{ support_page }}" color="#2C6ECB">Click Here</a>.
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333; background-color: #fff; border-radius: 0px 0px 8px 8px; padding: 0px 32PX 32px;"
                                        class="mob-paddding">
                                        <br /> Regards, <br />
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