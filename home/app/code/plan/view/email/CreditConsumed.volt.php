<html><head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Amazon by Cedcommerce Failed-Order Email</title>
    <style type="text/css">
        html,
        body {
            margin: 0 auto !important;
            padding: 0 !important;
            height: 100% !important;
            width: 100% !important;
        }
        
        .stack-column-center {
            display: inline-block !important;
        }
        /* Media Queries */
        
        @media screen and (max-width: 600px) {
            .email-container {
                width: 100% !important;
            }
            .email-inner-container {
                width: 84% !important;
            }
            .fluid,
            .fluid-centered {
                max-width: 100% !important;
                height: auto !important;
                margin-left: auto !important;
                margin-right: auto !important;
            }
            .fluid-centered {
                margin-left: auto !important;
                margin-right: auto !important;
            }
            .stack-column-center {
                width: 48% !important;
                direction: ltr !important;
                max-width: 100% !important;
                text-align: center !important;
            }
            .center-on-narrow {
                text-align: center !important;
                display: block !important;
                margin-left: auto !important;
                margin-right: auto !important;
                float: none !important;
            }
            table.center-on-narrow {
                display: inline-block !important;
            }
        }
        img + div { display:none; }
    </style>
</head>

<body bgcolor="#eeeeee" width="100%" style="margin:0;">
    <!-- email header starts -->
    <table cellspacing="0" cellpadding="0" border="0" align="center" width="600" style="margin:auto;" class="email-container">
        <!-- banner section starts -->
        <tbody>
            <tr>
                <td>
                    <img src="https://i.imgur.com/6LvmP4A.png" width="600" height="" alt="alt_text" border="0" align="center" style="width:100%;max-width:600px;">
                </td>
            </tr>
            <!-- banner section ends -->
        </tbody>
    </table>
    <!-- email header ends -->
    <!-- email body starts -->
    <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#ffffff" width="600" style="margin:auto;" class="email-container">
        <!-- main content starts -->
        <tbody>
            <tr>
                <td>
                    <img src="<?= $amazon_by_ced ?>" width="600" height="" alt="alt_text" border="0" align="center" style="width:100%;max-width:600px;">
                </td>
            </tr>
            <tr>
                <td style="padding:30px 30px 10px;font-size:14px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;line-height:22px;color:#666666;">Hi <?= $name ?>,
                    
                </td>
            </tr>
            
            <tr>
                <td style="padding:20px 30px;font-size:14px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;line-height:22px;color:#666666;">Thank you for using the <?= $sender ?> app – we hope you are enjoying this experience. Just letting you know that you have consumed 90% of your order credits and they are going to expire soon.<br><br>So, before it gets any more late and you lose out on our amazing services, we would advise you to upgrade to a new subscription plan at the earliest.<br>You can check all details of your subscription plan from your overview page by <a href="<?= $app_url ?>">clicking here.</a><br><br><b>If you have any questions or issues, please don’t hesitate to reply to this email, contact our live chat support or check out our FAQ page.</b></td>
            </tr>
            <tr>
                <td style="padding: 10px 32px;font-size:14px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;line-height:22px;color:#666666;">We’ll be happy to have you back!<br><br> Happy Selling!<br><br>Best Regards<br><br>Team CedCommerce
                </td>
            </tr>
            <!-- main content ends -->
        </tbody>
    </table>
    <!-- email body ends -->
    <!-- support section starts -->
    <table cellspacing="0" cellpadding="0" border="0" align="center" width="600" style="padding:30px 30px 0;text-align:center;" bgcolor="#f5f7f9" class="email-container">
        <tbody>
            <tr>
                <td width="32%" class="stack-column-center" valign="top" style="padding-bottom:30px;">
                    <table cellspacing="0" cellpadding="0" border="0" align="center" style="text-align: center;">
                        <tbody>
                            <tr>
                                <td>
                                    <img src="<?= $voice_support ?>" width="30" height="30" alt="alt_text" border="0" class="fluid">
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;line-height:20px;" class="center-on-narrow">
                                    <p style="color:#000000;font-weight:bold;margin:8px 0;">Voice Support</p>
                                    <span style="color:#666666;display:block;">USA: +1 (888)882-0953</span>
                                    <span style="color:#666666;display:block;">(Toll Free)</span>
                                    <span style="color:#666666;display:block;">INDIA: +91 7234976892</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
                <td width="32%" class="stack-column-center" valign="top" style="padding-bottom:30px;">
                    <table cellspacing="0" cellpadding="0" border="0" align="center" style="text-align: center;">
                        <tbody>
                            <tr>
                                <td>
                                    <img src="<?= $calendly ?>" width="30" height="30" alt="alt_text" border="0" class="fluid">
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;line-height:20px;" class="center-on-narrow">
                                    <p style="color:#000000;font-weight:bold;margin:8px 0;">Calendly</p>
                                    <span style="color:#666666;display:block;">Schedule meeting with us</span>
                                    <a href="https://calendly.com/scale-business-with-cedcommerce/shopify-amazon-integration" target="_blank" style="color:#333333;font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;display:block;">Schedule Meet</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
                <td width="32%" class="stack-column-center" valign="top" style="padding-bottom:30px;">
                    <table cellspacing="0" cellpadding="0" border="0" align="center" style="text-align: center;">
                        <tbody>
                            <tr>
                                <td>
                                    <img src="<?= $chat ?>" width="30" height="30" alt="alt_text" border="0" class="fluid">
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;line-height:20px;" class="center-on-narrow">
                                    <p style="color:#000000;font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;font-weight:bold;margin:8px 0;">Instant Chat</p>
                                    <span style="color:#666666;font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;display:block;">Connect to us on Live Chat</span>
                                    <a href="https://tawk.to/chat/5ca1b56a6bba460528009d93/default" target="_blank" style="color:#333333;font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;display:block;">Connect Us</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            <tr>
            </tr>
            <tr>
                <td width="32%" class="stack-column-center" valign="top" style="padding-bottom:30px;">
                    <table cellspacing="0" cellpadding="0" border="0" align="center" style="text-align: center;">
                        <tbody>
                            <tr>
                                <td>
                                    <img src="<?= $email_url ?>" width="30" height="30" alt="alt_text" border="0" class="fluid">
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;line-height:20px;" class="center-on-narrow">
                                    <p style="color:#000000;font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;font-weight:bold;margin:8px 0;">Email Support</p>
                                    <a href="mailto:channel-support@cedcommerce.com" target="_blank" style="color:#333333;font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;display:block;">Mail Us</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
                <td width="32%" class="stack-column-center" valign="top" style="padding-bottom:30px;">
                    <table cellspacing="0" cellpadding="0" border="0" align="center" style="text-align: center;">
                        <tbody>
                            <tr>
                                <td>
                                    <img src="<?= $skype ?>" width="30" height="30" alt="alt_text" border="0" class="fluid">
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;line-height:20px;" class="center-on-narrow">
                                    <p style="color:#000000;font-weight:bold;margin:8px 0;">Skype Support</p>
                                    <a href="https://join.skype.com/xV5r9L7s6jFG" target="_blank" style="color:#333333;display:block;">Support: Amazon Channel by CedCommerce</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
                <td width="32%" class="stack-column-center" valign="top" style="padding-bottom:30px;">
                    <table cellspacing="0" cellpadding="0" border="0" align="center" style="text-align: center;">
                        <tbody>
                            <tr>
                                <td>
                                    <img src="<?= $watsapp ?>" width="30" height="30" alt="alt_text" border="0" class="fluid">
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;line-height:20px;" class="center-on-narrow">
                                    <p style="color:#000000;font-size:12px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;font-weight:bold;margin:8px 0;">Live Chat on Whatsapp</p>
                                    <a href="https://chat.whatsapp.com/GOFQ2Gsg7rdBjBSzE9NGAA" target="_blank" style="color:#333333;display:block;">Chat Now</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>
    <!-- support section starts -->
    <!-- email footer starts -->
    <table cellspacing="0" cellpadding="0" border="0" width="600" bgcolor="#fbfbfb" class="email-container" style="margin:auto;">
        <!-- address section starts -->
        <tbody>
            <tr>
                <td width="60%" class="stack-column" valign="top" style="padding:30px;">
                    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tbody>
                            <tr>
                                <td style="padding:0 0 15px;color:#333333;font-weight: 800;font-size: 14px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;text-transform: uppercase;">
                                    Address
                                </td>
                            </tr>
                            <tr>
                                <td style="color:#000000;line-height:20px;font-size:13px;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;">
                                    CedCommerce Inc.
                                    <br> 1B12 N Columbia Blvd Suite
                                    <br> C15-653026 Portland, Oregon, 97217, USA
                                    <br><br>
                                    <a href="https://www.facebook.com/CedCommerce/" target="_blank" style="display:inline-block;margin-right:30px;background-color: #3b5998;height:24px">
                                        <img title="Facebook" src="<?= $facebook ?>" alt="fb" width="24px">
                                    </a>
                                    <a href="https://twitter.com/cedcommerce" target="_blank" style="display:inline-block;margin-right:30px;">
                                        <img title="Twitter" src="<?= $twitter ?>" alt="tw" width="24px">
                                    </a>
                                    <a href="https://www.instagram.com/cedcommerce/" target="_blank" style="display:inline-block;">
                                        <img title="Instagram" src="<?= $insta ?>" alt="ig" width="24px">
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
                <td width="18%" class="stack-column" valign="top" style="padding:40px 30px 30px 0;text-align:right;">
                    
                </td>
            </tr>
            <!-- address section ends -->
        </tbody>
    </table>
    <!-- email footer ends -->







</body></html>
