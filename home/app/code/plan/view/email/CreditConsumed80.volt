<html>

<head>
    <meta charset="utf-8" />
    <meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>80% of your order credits used</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">
    <style type="text/css">
        @import url('https://fonts.googleapis.com/css2?family=Roboto&display=swap');

        html,
        body {
            margin: 0 auto !important;
            padding: 0 !important;
            height: 100% !important;
            width: 100% !important;
            font-family: 'Roboto', sans-serif;
            background-color: #F6F6F7;
        }

        /* Media Queries */
        @media screen and (max-width: 600px) {
            .email-container {
                width: 100% !important;
                margin: auto !important;
                padding: 0 25px !important;
            }

            .fluid {
                max-width: 100% !important;
                height: auto !important;
                margin-left: auto !important;
                margin-right: auto !important;
            }

            .heading {
                font-size: 16px !important;
                line-height: 24px !important;
            }

            .social-twitter {
                padding: 9px 8px !important;
            }

            .social-linkedin {
                padding: 8px !important;
            }

            .bgheight {
                height: 48px !important;
            }

            .mob-paddding {
                padding: 12px 15px !important;
            }

            .pt-0 {
                padding-top: 0 !important;
            }

            .banner-icon {
                width: 80px !important;
            }

            .line-break {
                padding-top: 0 !important;
            }

            .import {
                padding-top: 0 !important;
                padding-bottom: 20px !important;
            }
        }
    </style>
</head>

<body width="100%" style="margin: 0;">
    <table cellspacing="0" cellpadding="0" border="0" width="100%" bgcolor="#eeeeee" align="center"
        style="font-family: 'Roboto', sans-serif;">
        <tbody>
            <tr>
                <td>
                    <!-- email body starts -->
                    <table cellspacing="0" cellpadding="0" border="0" width="640" height="100%" bgcolor="#F6F6F7"
                        class="email-container" style="margin: auto;">
                        <tbody>
                            <tr style="text-align: center;">
                                <td style="display:inline-block; text-align: right; padding-top: 34px;">
                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png" style="width: 100%;max-width: 24px;">
                                </td>
                                <td style="font-size: 16px;line-height: 20px;color: #202223;display: inline-block;font-weight: 400; padding-top: 34px;   padding-left: 12px;"
                                    class="heading mob-paddding">
                                    {{ app_name }}
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size: 14px;  color: #E1E3E5; display: block; padding: 14px 40px 24px;"
                                    class="line-break">
                                    <span style="display: block; border-top: 1px solid #ece9ff;"></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <!-- email header ends -->
                    <!-- email content starts -->
                    <table cellspacing="0" cellpadding="0" border="0" width="640" bgcolor="#F6F6F7"
                        class="email-container" style="margin: auto; padding: 0px 40px;">
                        <tbody>
                            <tr style="text-align:center">
                                <td style="display:inline-block;">
                                    <table style="background-color: #F6F6F7;" class="import">
                                        <tbody>
                                            <tr style="text-align:center">
                                                <td style="display:table-cell;">
                                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/limit_70.png" style="width: 100%; max-width: 250px;">

                                                </td>
                                            </tr>
                                            <tr>
                                                <td
                                                    style="font-size:26px;line-height:32px;padding-top: 12px;padding-bottom: 20px; color: #333333;text-align: center">
                                                    You’ve used 80% of your Order Limit
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                                <td style="font-size: 14px; line-height: 20px; color: #333333; display: inline-block; background-color: #fff; padding:32px 32px 16px;border-radius: 8px 8px 0 0;"
                                    class="mob-paddding">

                                    <span
                                        style="display:block;padding-bottom: 30px;text-align: left;font-weight: 600;">Hello
                                        {{ name }},</span>
                                    <p>
                                        We wanted to inform you that you have now reached 80% of your credit limit usage
                                        for the current billing cycle. This means that if you exceed your credit limit,
                                        you will be charged an additional fee of $3 for every 10 orders processed.

                                        Recommened plan:
                                        {% for plan_link in recommended_plan_link %}
                                        <!-- <button style="padding-top: 8px;"> -->
                                        <a href="{{ plan_link }}"> Purchase Now </a>
                                        <!-- </button> -->
                                        {% endfor %}
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <!-- email content ends -->
                    <table cellspacing="0" cellpadding="0" border="0" width="640" bgcolor="#F6F6F7"
                        class="email-container" style="margin: auto; padding: 0px 40px;">
                        <tbody>
                            <tr>
                                <td style="font-size: 14px; line-height: 20px; color: #333333; display: inline-block; background-color: #fff; padding: 0px 32px 0px;"
                                    class="mob-paddding">
                                    In case you encounter an issue or have any queries get in touch with our team of
                                    experts here:
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size: 14px; line-height: 20px; color: #333333; background-color: #fff; padding: 0px 32px 24px;"
                                    class="mob-paddding">
                                    <a href="mailto:channel-support@cedcommerce.com" color="#2C6ECB">Contact</a>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size: 14px; line-height: 20px; color: #333333; display: block; background-color: #fff; border-radius: 0px 0px 8px 8px; padding: 0px 32PX 32px;"
                                    class="mob-paddding">
                                    Regards, <br />
                                    <span style="font-weight: 600;">Team CedCommerce</span><br />
                                    <br />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <!-- email footer starts -->
                    <table cellspacing="0" cellpadding="0" border="0" width="640" bgcolor="#F6F6F7" style="margin: auto;
                        color: #6D7175;
                        line-height: 20px;
                        font-size: 14px;">
                        <tbody>

                            <tr>
                                <td style="font-size: 14px;  color: #E1E3E5; display: block; padding: 0 40px 20px;"
                                    class="mob-paddding">
                                    <span style="display: block; border-top: 1px solid #ece9ff;"></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <table cellspacing="0" cellpadding="0" border="0" width="640" bgcolor="#F6F6F7"
                        class="email-container pt-0" style="margin: auto;    
                        color: #6D7175;
                        line-height: 20px;
                        font-size: 14px;">
                        <tbody>
                            <tr>
                                <td style="font-size: 14px; text-align: center; color: #616771;">
                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png" style="width: 100%;max-width: 24px;">
                                </td>
                            </tr>
                            <tr>
                                <td style="color:#262626;line-height:17px;padding-top:16px;text-align:center">
                                    Team CedCommerce
                                </td>
                            </tr>
                            <tr style="text-align: center">
                                <td
                                    style="color:#262626;line-height:17px;padding-top:8px;text-align:center;font-size: 10px;max-width: 230px;display: inline-block;">
                                    CedCommerce Inc. 1B12 N Columbia Blvd Suite C15-653026 Portland, Oregon, 97217, USA
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
                        </tbody>
                    </table>
                    <!-- email footer ends -->
                </td>
            </tr>
        </tbody>
    </table>
</body>

</html>