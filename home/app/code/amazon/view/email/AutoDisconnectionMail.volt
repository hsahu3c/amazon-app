
<html>

<head>
    <meta charset="utf-8" />
    <meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Amazon Account Disconnection Notice</title>
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

            .logo {
                width: 10% !important;
            }

            .subject-title {
                font-size: 20px !important;
                line-height: 28px !important;
            }
        }
    </style>
</head>

<body width="100%" style="margin: 0; background-color: #fff;">
    <table cellspacing="0" cellpadding="0" width="100%" style="margin-left: auto; margin-right: auto">
        <tr>
            <td width="100%">
                <table class="email-container" max-width="100%" cellspacing="0" cellpadding="0" width="600"
                    style="margin-left:auto; margin-right: auto;">
                    <tr>
                        <td class="mob-paddding" style="padding-left: 20px;padding-right: 20px;background: #F6F6F7;">
                            <!-- email body starts -->
                            <table cellspacing=" 0" cellpadding="0" width="100%" height="100%"
                                style="margin-left: auto; margin-right: auto; background-color: #F6F6F7; border: 0">
                                <tr>
                                    <td>
                                        <table cellspacing="0" cellpadding="0"
                                            style="background-color: #F6F6F7; width: 100%;">
                                            <tr>
                                                <td class="logo" style="text-align: right; padding-top: 20px;"
                                                    width="35%">
                                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png"
                                                        style="width: 100%;max-width: 24px;">
                                                </td>
                                                <td width="65%"
                                                    style="font-size: 16px;line-height: 20px;color: #202223;font-weight: 400; padding-top: 20px;   padding-left: 12px; text-align: left;"
                                                    class="heading">
                                                    {{app_name}}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%" style="padding-top: 14px; padding-bottom: 20px;"
                                        class="line-break">
                                        <span style="display: block; border-top: 1px solid #E1E3E5;"></span>
                                    </td>
                                </tr>
                            </table>
                            <!-- email header ends -->
                            <!-- email content starts -->
                            <table style="background-color: #F6F6F7; margin-left: auto; margin-right: auto;"
                                width="100%" class="import">
                                <tr>
                                    <td width="100%" style="text-align: center;">
                                        <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/reauthorize.png">
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="font-size:26px;line-height:32px;padding: 12px 40px 20px 40px; color: #202223;text-align: center ; font-weight: 400;"
                                        class="subject-title">
                                        Amazon Account Disconnection Notice
                                    </td>
                                </tr>
                            </table>
                            <table>
                                <tr>
                                    <td style="background: #ffffff;padding: 32px;border-radius: 8px;"
                                        class="mob-paddding">
                                        <table cellspacing="0" cellpadding="0" width="100%" style="border: 0;">
                                            <tr>
                                                <td
                                                    style="font-size: 14px;line-height: 20px;color: #202223;font-weight: 600;padding-bottom: 8px;">
                                                    Hello {{ name }},
                                                </td>
                                            </tr>
                                            <tr>
                                                <td
                                                    style="font-size: 14px;line-height: 20px;color: #202223;padding-top: 8px;padding-bottom: 8px;">
                                                    We are writing to inform you that we will be disconnecting your Amazon account from the {{app_name}} app due to the following reason:
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 14px;line-height: 20px;color: #202223;padding-top: 8px;padding-bottom: 8px;">
                                                    <ul style="margin: 0; padding-left: 20px;">
                                                        {% if error_type == 'invalid_grant' %}
                                                        <li style="padding-bottom: 8px;"><b>Token Expired:</b> Your authorization token has expired and re-authorization has not been completed in the past 6 months.</li>
                                                        {% elseif error_type == 'InvalidInput' %}
                                                        <li style="padding-bottom: 8px;"><b>Vacation Mode:</b> Your Amazon account is in vacation mode from past 6 month.</li>
                                                        {% elseif error_type == 'Unauthorized' %}
                                                        <li style="padding-bottom: 8px;"><b>Access Denied:</b> Required permissions are not present and have been missing from past 6 months.</li>
                                                        {% endif %}
                                                    </ul>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td
                                                    style="font-size: 14px;line-height: 20px;color: #202223;padding-top: 8px;padding-bottom: 8px;">
                                                    The following Amazon account will be disconnected:
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-top: 8px;padding-bottom: 8px;">
                                                    <table cellspacing="0" cellpadding="8" width="100%" style="border: 1px solid #E1E3E5; border-collapse: collapse;">
                                                        <tr style="background-color: #F6F6F7;">
                                                            <td style="border: 1px solid #E1E3E5; font-size: 14px; font-weight: 600; color: #202223;">Seller ID</td>
                                                            <td style="border: 1px solid #E1E3E5; font-size: 14px; font-weight: 600; color: #202223;">Region</td>
                                                            <td style="border: 1px solid #E1E3E5; font-size: 14px; font-weight: 600; color: #202223;">Marketplace</td>
                                                            <td style="border: 1px solid #E1E3E5; font-size: 14px; font-weight: 600; color: #202223;">Problem</td>
                                                        </tr>
                                                        <tr>
                                                            <td style="border: 1px solid #E1E3E5; font-size: 14px; color: #202223;">{{ seller_id }}</td>
                                                            <td style="border: 1px solid #E1E3E5; font-size: 14px; color: #202223;">{{ region }}</td>
                                                            <td style="border: 1px solid #E1E3E5; font-size: 14px; color: #202223;">{{ marketplace_name }}</td>
                                                            <td style="border: 1px solid #E1E3E5; font-size: 14px; color: #202223;">
                                                                {% if error_type == 'invalid_grant' %}
                                                                    Token Expired
                                                                {% elseif error_type == 'InvalidInput' %}
                                                                    Vacation Mode
                                                                {% elseif error_type == 'Unauthorized' %}
                                                                    Permission missing
                                                                {% else %}
                                                                    {{ error_type }}
                                                                {% endif %}
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td
                                                    style="font-size: 14px;line-height: 20px;color: #202223;padding-top: 8px;padding-bottom: 8px;">
                                                    If you wish to reconnect your Amazon account, please ensure that:
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 14px;line-height: 20px;color: #202223;padding-top: 8px;padding-bottom: 8px;">
                                                    <ul style="margin: 0; padding-left: 20px;">
                                                        <li style="padding-bottom: 8px;">Your authorization token is valid and up-to-date.</li>
                                                    </ul>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td
                                                    style="font-size: 14px;line-height: 20px;color: #202223;padding-top: 8px;">
                                                    If you need any further assistance or have questions about this disconnection, please
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 14px;line-height: 20px;padding-bottom: 8px;">
                                                    <a href="mailto:channel-support@cedcommerce.com" target="_blank" style="color: #2C6ECB;">Contact Us</a>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td
                                                    style="font-size: 14px;line-height: 20px;color: #202223;padding-top: 8px;padding-bottom: 0px;">
                                                    Regards,
                                                </td>
                                            </tr>
                                            <tr>
                                                <td
                                                    style="font-size: 14px;line-height: 20px;color: #202223;padding-top: 2px;padding-bottom: 8px; font-weight: bold;">
                                                    Team CedCommerce
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- email footer starts -->
                            <table cellspacing="0" cellpadding="0" width="100%" class=" pt-0"
                                style="border: 0; background-color: #F6F6F7; margin-left: auto; margin-right: auto;">
                                <tr>
                                    <td style="padding-bottom: 20px;">
                                        <span style="border-top: 1px solid #E1E3E5; display: block;"></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="text-align: center;">
                                        <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png" style="width: 100%;max-width: 24px;" />
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="color:#202223;line-height:20px;padding-top:16px;text-align:center;font-size: 14px;">
                                        Team
                                        CedCommerce
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="color:#202223;line-height:18px;padding-top:8px;text-align:center;font-size: 12px;max-width: 230px;">
                                        CedCommerce Inc. 1B12 N Columbia Blvd Suite C15-653026 Portland, Oregon,
                                        97217, USA
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="color:#202223;line-height:17px;padding-top:10px;padding-bottom:48px;text-align:center;">
                                        <a href="https://www.facebook.com/CedCommerce/" title="fb"
                                            style="text-decoration: none;" target="_blank">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/fb.png" />
                                        </a>
                                        <a href="https://twitter.com/cedcommerce/" title="twitter"
                                            style="padding-left:20px; text-decoration: none;" target="_blank">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/x.png" />
                                        </a>
                                        <a href="https://www.instagram.com/CedCommerce/" title="social-insta"
                                            style="padding-left:20px; text-decoration: none;" target="_blank">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/insta.png" />
                                        </a>
                                        <a href="https://www.linkedin.com/company/cedcommerce" title="linkedin"
                                            style="padding-left: 20px; text-decoration: none;" target="_blank">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/linkedin.png" />
                                        </a>
                                        <a href="https://www.youtube.com/channel/UCLRUCC_jvKf4tfZ2omjaW8Q"
                                            title="youtube" style="padding-left: 20px; text-decoration: none;"
                                            target="_blank">
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

