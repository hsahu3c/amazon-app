<html>

<head>
    <meta charset="utf-8" />
    <meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Weekly Order(s) Sync Report</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">
    <style type="text/css">
        @import url('https://fonts.googleapis.com/css2?family=Roboto&display=swap');

        html, body {
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

        @media screen and (max-width: 640px) {
            table[class="email-container"] { width: 100% !important; }
            .heading { font-size: 16px !important; line-height: 24px !important; }
            .mob-paddding { padding: 12px 15px !important; }
        }

        @media screen and (max-width: 500px) {
            table[class="email-container"] { width: 100% !important; }
            .logo { width: 10% !important; }
            .subject-title { font-size: 20px !important; line-height: 28px !important; }
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

                            <!-- email header -->
                            <table cellspacing="0" cellpadding="0" width="100%" height="100%"
                                style="margin-left: auto; margin-right: auto; background-color: #F6F6F7; border: 0">
                                <tr>
                                    <td>
                                        <table cellspacing="0" cellpadding="0" style="background-color: #F6F6F7; width: 100%;">
                                            <tr>
                                                <td class="logo" style="text-align: right; padding-top: 20px;" width="35%">
                                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png"
                                                        style="width: 100%;max-width: 24px;">
                                                </td>
                                                <td width="65%"
                                                    style="font-size: 16px;line-height: 20px;color: #202223;font-weight: 400;padding-top: 20px;padding-left: 12px;text-align: left;"
                                                    class="heading">
                                                    {{ app_name }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%" style="padding-top: 14px; padding-bottom: 20px;">
                                        <span style="display: block; border-top: 1px solid #E1E3E5;"></span>
                                    </td>
                                </tr>
                            </table>
                            <!-- email header ends -->

                            <!-- subject banner -->
                            <table style="background-color: #F6F6F7; margin-left: auto; margin-right: auto;" width="100%">
                                <tr>
                                    <td width="100%"
                                        style="font-size:26px;line-height:32px;padding: 12px 40px 20px 40px;color: #202223;text-align: center;font-weight: 400;"
                                        class="subject-title">
                                        Your Weekly Order(s) Sync Report is Ready
                                    </td>
                                </tr>
                            </table>

                            <!-- email body -->
                            <table>
                                <tr>
                                    <td style="background: #ffffff;padding: 32px;border-radius: 8px;" class="mob-paddding">
                                        <table cellspacing="0" cellpadding="0" width="100%" style="border: 0;">

                                            <!-- greeting -->
                                            <tr>
                                                <td style="font-size: 14px;line-height: 20px;color: #202223;font-weight: 600;padding-bottom: 8px;">
                                                    Dear {{ name }},
                                                </td>
                                            </tr>

                                            <!-- intro -->
                                            <tr>
                                                <td style="font-size: 14px;line-height: 22px;color: #202223;padding-top: 4px;padding-bottom: 12px;">
                                                    Please find your <strong>Weekly Order(s) Sync Report</strong> for the period
                                                    <strong>{{ date_range }}</strong> attached to this email.
                                                    During this period, <strong>{{ total_count }} order(s)</strong> could not be
                                                    synced from Amazon to your Shopify store.
                                                    <br /><br />
                                                    This report provides a detailed overview of Amazon orders that could not be synced
                                                    to your Shopify store, or were re-attempted and successfully created thereafter.
                                                    Under the <strong>Sync Status</strong> column in the attached report, you can find the current status
                                                    of each order within our app, along with the reason(s) for each failure — so you
                                                    can review them and take the necessary corrective action promptly.
                                                </td>
                                            </tr>

                                            <!-- attachment note -->
                                            <tr>
                                                <td style="background-color: #F0FFF8;border-radius: 6px;padding: 12px 16px;margin-top: 8px;margin-bottom: 8px;">
                                                    <span style="font-size: 14px;line-height: 20px;color: #202223;">
                                                        📎 <strong>The report CSV is attached to this email.</strong>
                                                        Please open the attachment to review the complete order sync details.
                                                    </span>
                                                </td>
                                            </tr>

                                            <!-- what to do next -->
                                            <tr>
                                                <td style="font-size: 14px;line-height: 20px;color: #202223;font-weight: 600;padding-top: 12px;padding-bottom: 6px;">
                                                    Recommended Next Steps
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 14px;line-height: 24px;color: #202223;padding-bottom: 12px;">
                                                    <ol style="margin: 0; padding-left: 18px;">
                                                        <li style="padding-bottom: 6px;">Open the attached report and review the <strong>Failed Reason</strong> column for each order to understand the sync issue.</li>
                                                        <li>Resolve the identified issues on Amazon or your Shopify store as applicable, and re-trigger the sync if needed.</li>
                                                    </ol>
                                                </td>
                                            </tr>

                                            <!-- support -->
                                            <tr>
                                                <td style="font-size: 14px;line-height: 20px;color: #202223;padding-bottom: 8px;padding-top: 4px;">
                                                    Need help interpreting the errors or resolving sync issues? Our support team is ready to assist:&nbsp;
                                                    <a href="https://support.cedcommerce.com/portal/en/newticket?departmentId=132692000000010772&layoutId=132692000092595993"
                                                        target="_blank" style="color: #2C6ECB;font-weight: 600;">Contact Support</a>
                                                </td>
                                            </tr>

                                            <!-- sign-off -->
                                            <tr>
                                                <td style="font-size: 14px;line-height: 20px;color: #202223;padding-top: 12px;padding-bottom: 2px;">
                                                    Regards,
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 14px;line-height: 20px;color: #202223;padding-top: 2px;padding-bottom: 8px;font-weight: bold;">
                                                    Team CedCommerce
                                                </td>
                                            </tr>

                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- email footer -->
                            <table cellspacing="0" cellpadding="0" width="100%"
                                style="border: 0; background-color: #F6F6F7; margin-left: auto; margin-right: auto;">
                                <tr>
                                    <td style="padding-bottom: 20px;">
                                        <span style="border-top: 1px solid #E1E3E5; display: block;"></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="text-align: center;">
                                        <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png"
                                            style="width: 100%;max-width: 24px;" />
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="color:#202223;line-height:20px;padding-top:16px;text-align:center;font-size: 14px;">
                                        Team CedCommerce
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="color:#202223;line-height:18px;padding-top:8px;text-align:center;font-size: 12px;max-width: 230px;">
                                        CedCommerce Inc. 1B12 N Columbia Blvd Suite <br />C15-653026 Portland, Oregon, 97217, USA
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="color:#202223;line-height:17px;padding-top:10px;padding-bottom:48px;text-align:center;">
                                        <a href="https://www.facebook.com/CedCommerce/" style="text-decoration: none;" target="_blank">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/fb.png" />
                                        </a>
                                        <a href="https://twitter.com/cedcommerce/" style="padding-left:20px; text-decoration: none;" target="_blank">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/twitter.png" />
                                        </a>
                                        <a href="https://www.instagram.com/CedCommerce/" style="padding-left:20px; text-decoration: none;" target="_blank">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/insta.png" />
                                        </a>
                                        <a href="https://www.linkedin.com/company/cedcommerce" style="padding-left: 20px; text-decoration: none;" target="_blank">
                                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/linkedin.png" />
                                        </a>
                                        <a href="https://www.youtube.com/channel/UCLRUCC_jvKf4tfZ2omjaW8Q" style="padding-left: 20px; text-decoration: none;" target="_blank">
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