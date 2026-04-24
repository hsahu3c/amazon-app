<html>

<head>
    <meta charset="utf-8" />
    <meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Plan Upgraded</title>
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
                                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/upgraded.png">
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="100%"
                                                    style="font-size:26px;line-height:32px;padding-top: 12px;padding-bottom: 20px; color: #333333;text-align: center">
                                                    Plan Upgraded</td>
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
                                            We're glad to inform you that your <b>{{ plan_details['title'] }} plan</b> has been successfully upgraded. A payment of <b>${{
                                                plan_details['custom_price'] }}</b> has been processed, and your plan now includes syncing <b>{{ plan_details['description'] }}</b> — ensuring seamless integration between Amazon and Shopify.
                                           <!-- Your payment of  <b>${{
                                                plan_details['custom_price'] }}</b>  for the <b>{{ plan_details['title'] }} plan</b> has been processed successfully. Your plan has been upgraded, allowing you to sync <b>{{ plan_details['description'] }}</b> seamlessly. -->
                                         </span>
                                        <!-- <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;margin-top: 10px;"> Your current subscription plan - <b>({{
                                                plan_details['title'] }} - ${{
                                                plan_details['custom_price'] }} with {{ plan_details['description']
                                                }})</b>.
                                        </span> -->
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;margin-top: 10px;">
                                           <!-- We appreciate your trust and hope our services help your business grow. If you have any questions or need plan adjustments, we're happy to help! -->
                                           We truly appreciate your trust in CedCommerce and hope our services contribute to your business growth.

                                        </span>
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;margin-top: 10px;">
                                            If you have any questions or need assistance with your plan or usage, feel free to reach out to us anytime via our <a href="{{ support_page }}" color="#2C6ECB">Support Portal</a>.
                                            <!-- If you have any changes to your plan or require additional features,
                                            please do not hesitate to <a href="{{ support_page }}" color="#2C6ECB">contact
                                                us</a>. We are always happy to help and
                                            ensure you get the most out of your plan. -->
                                            
                                        </span>
                                    </td>
                                </tr>
                            </table>
                            <table cellspacing="0" cellpadding="0" width="100%"
                                style="border: 0; background-color: #F6F6F7;">
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333;  background-color: #fff; padding: 0px 32px 0px;"
                                        class="mob-paddding"> Thanks for choosing CedCommerce!
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