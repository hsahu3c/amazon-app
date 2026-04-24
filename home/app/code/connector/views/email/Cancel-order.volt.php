<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Cancel Order</title>
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
            background-color: #F6F6F7;
        }
        /* Media Queries */
        
        @media screen and (max-width: 600px) {
            .email-container {
                width: 100% !important;
                margin: auto !important;
                padding: 22px 15px 0!important;
            }
            .fluid {
                max-width: 100% !important;
                height: auto !important;
                margin-left: auto !important;
                margin-right: auto !important;
            }
            .heading {
                font-size: 16px !important;
                line-height: 24px!important;
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
            .width-unset{
                min-width: unset !important;
            }
            .pt-0 {
                padding-top: 0!important;
            }
            .banner-icon {
                width: 80px!important;
            }
            .line-break {
                padding-top: 0 !important;
            }
            .import {
                padding-top: 0 !important;
                padding-bottom: 20px !important;
            }
            .mob-padding1{
                padding-bottom: unset !important;
            }
        }
    </style>
</head>

<body width="100%" style="margin: 0;text-align: center;background-color: #fff;">
    <table cellspacing="0" cellpadding="0" border="0" width="100%" bgcolor="#F6F6F7" align="center" style="font-family: 'Roboto', sans-serif;display: inline-block;max-width: 640px;">
        <tbody>
            <tr>
                <td>
                    <!-- email body starts -->
                    <table cellspacing="0" cellpadding="0" border="0" width="640" height="100%" bgcolor="#F6F6F7 " class="email-container" style="margin: auto; padding: 32px 40px 0;">
                        <tbody>
                            <tr style="text-align: center; ">
                                <td style="display:inline-block; text-align: right; ">
                                    <img src="https://i.imgur.com/EM1Divg.png " style="width: 100%;max-width: 24px; " alt="CedCommerce Logo">
                                </td>
                                <td style="font-size: 16px;line-height: 20px;color: #202223;display: inline-block;font-weight: 400; padding-left: 12px; " class="heading mob-paddding ">
                                    
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size: 14px; color: #E1E3E5; display: block;; padding: 14px 40px 0; " class="line-break ">
                                    <span style="display: block; border-top: 1px solid #ece9ff; "></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            <tr>
                <td>
                    <table cellspacing="0 " cellpadding="0 " border="0 " width="640" bgcolor="#F6F6F7 " class="email-container " style="margin: auto; padding: 0px 40px; ">
                        <tbody>
                            <tr style="text-align:center ">
                                <td style="display:inline-block; ">
                                    <table style="padding: 20px 32px 20px;background-color: #F6F6F7;" class="import">
                                        <tbody>
                                            <tr style="text-align:center ">
                                                <td style="display:table-cell; ">
                                                    <img src="https://i.imgur.com/F6DIa2p.png" style="width: 100%;max-width: 93px; " alt="Order cancel icon">
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="font-size:26px;line-height:32px;padding-top: 12px;color: #333333;text-align: center; ">
                                                    Cancel Order Sync Failure
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            <tr style=" background: #fff; display: inline-block; padding:32px 0 0; border-top-left-radius: 8px; border-top-right-radius: 8px; ">
                                <td style="font-size: 14px; line-height: 20px; color: #333333; display: inline-block; background-color: #fff; padding:0 32px; " class="mob-paddding ">
                                    <span style="display:block;padding-bottom: 30px;text-align: left;font-weight: 600; ">Hello <?= $username ?>,</span>
                                    <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif; color: #333333;padding-bottom: 20px; " class="mob-padding1">                             
                                        We would like to immediately draw your attention to the fact that your canceled order from your &nbsp;<?php if ($source_marketplace == 'Amazon') { ?>Amazon Seller Center account<?php } else { ?><?= $target_marketplace ?> store<?php } ?>&nbsp; has failed to be synced on your <?= $target_marketplace ?> store. This means that you cannot process them from <?= $target_marketplace ?> until they are successfully synchronized with the store.
                           </span>
                                </td>
                            </tr>    
                            <tr style="text-align:left">
                                <td style="font-size: 14px; line-height: 20px; color: #333333; display:block; background-color: #fff; padding: 0px 32px 24px;" class="mob-paddding">
                                    <table cellspacing="0" cellpadding="0" style="padding: 20px; border: 1px solid #eee;border-radius: 8px;color:#202223;text-align: left;font-size: 14px;">                   
                                      <tr>
                                        <td style="font-size:14px;font-weight:600;line-height:20px;min-width: 160px;" class="width-unset"><?= $target_marketplace ?> Order ID</td>
                                        <td style="font-size:14px;font-weight:600;line-height:20px;min-width: 160px;" class="width-unset"><?= $source_marketplace ?> Order ID</td>
                                        <td style="font-size:14px;font-weight:600;line-height:20px;    vertical-align: text-top;">Reason for failure</td>
                                      </tr>
                                      <tr>
                                        <td style="padding-top: 10px;border-bottom: 1px solid #eee;"></td>
                                        <td style="padding-top: 10px;border-bottom: 1px solid #eee;"></td>
                                        <td style="padding-top: 10px;border-bottom: 1px solid #eee;"></td>
                                      </tr>
                                      <tr>
                                        <td style="font-size:14px;padding-top:10px;line-height: 20px;vertical-align: text-top;"><?= $target_order_id ?></td>
                                        <td style="font-size:14px;padding-top:10px;line-height: 20px; vertical-align: text-top;"><?= $source_order_id ?></td>
                                        <td style="font-size:14px;padding-top:10px;line-height: 20px;">
                                            <ul style="padding-left: 16px;">
                                                <!-- <li><?= $reason ?></li> -->
                                                <?php foreach ($reasons as $reason) { ?>
                                                <li style="    padding-top: 8px;"><?= $reason ?></li>
                                                <?php } ?>
                                            </ul>


                                            </td>
                                      </tr>
                                    </table>
                                </td>
                            </tr>                 
                            <tr style="text-align:left">
                                <td style="font-size: 14px; line-height: 20px; color: #333333; display: inline-block; background-color: #fff; padding: 0px 32px 24px; " class="mob-paddding ">
                                    To get more details you’ll need to go to the “Failed Canceled Orders” section of the app, where you’ll be able to find all the necessary details of this Order. 
                                </td>
                            </tr>
                            <tr style="text-align:left">
                                <td style="font-size: 14px; line-height: 20px; color: #333333; display: inline-block; background-color: #fff; padding: 0px 32px 24px; " class="mob-paddding ">
                                    If this does not work, you can get in touch with one of our industry experts by mailing us at <a href="mailto:channel-support@cedcommerce.com" style="color: #2C6ECB ">channel-support@cedcommerce.com</a>
                                </td>
                            </tr>
                          
                            <tr style="text-align:left">
                                <td style="font-size: 14px; line-height: 20px; color: #333333; display: block; background-color: #fff; border-radius: 0px 0px 8px 8px; padding: 0px 32PX 32px; " class="mob-paddding ">
                                   Thanks and Regards, <br />
                                    <span style="font-weight: 600; ">Team CedCommerce</span><br />
                                    <br />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            <tr>
                <td>
                    <!-- email footer starts -->
                    <table cellspacing="0" cellpadding="0" border="0" width="640" bgcolor="#F6F6F7" class="email-container" style="margin: auto; padding: 20px 64px 20px; color: #6D7175; line-height: 20px; font-size: 14px;">
                        <tbody>
                            <tr>
                                <!-- <td style="font-size: 14px; text-align: center; color: #616771; ">
                                    This email was sent to
                                    <a href="# " style="color: #2C6ECB ">channel-support@cedcommerce.com</a>. If you’d rather not receive this kind of email, you can
                                    <a href="# " style="color: #2C6ECB ">unsubscribe or manage your email preferences.</a>
                                </td> -->
                            </tr>
                            <tr>
                                <td style="font-size: 14px; color: #E1E3E5; display: block;; padding: 20px 10px 0; " class="mob-paddding ">
                                    <span style="display: block; border-top: 1px solid #ece9ff; "></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            <tr>
                <td>
                    <!-- email content ends -->
                    <table cellspacing="0" cellpadding="0" border="0" width="640" bgcolor="#F6F6F7" class="email-container pt-0" style="margin: auto; padding: 0 64px 34px; color: #6D7175; line-height: 20px; font-size: 14px;">
                        <tbody>
                            <tr>
                                <td style="font-size: 14px; text-align: center; color: #616771; ">
                                    <img src="https://i.imgur.com/EM1Divg.png " style="width: 100%;max-width: 24px; " alt="CedCommerce Logo">
                                </td>
                            </tr>
                            <tr>
                                <td style="color:#262626;line-height:17px;padding-top:16px;text-align:center ">
                                    Team CedCommerce
                                </td>
                            </tr>
                            <tr style="text-align: center ">
                                <td style="color:#262626;line-height:17px;padding-top:8px;font-size: 10px;max-width: 230px;display: inline-block; ">
                                    USA Portland, Oregon CedCommerce Inc, 1812 N Columbia Blvd, Suite C15-653026, Portland, Oregon, 97217
                                </td>
                            </tr>
                            <tr style="text-align: center ">
                                <td style="color:#262626;line-height:17px;padding-top:10px;padding-bottom:14px;display: inline-block; ">
                                    <a href="# " title="fb " style="display: inline-block; ">
                                        <img src="https://i.imgur.com/EwLeLv4.png" alt="facebook">
                                    </a>
                                    <a href="# " title="twitter " style="padding-left:20px;display: inline-block; ">
                                        <img src="https://i.imgur.com/DDSYIK1.png" alt="Twitter">
                                    </a>
                                    <a href="# " title="social-insta " style="padding-left:20px;display: inline-block; ">
                                        <img src="https://i.imgur.com/AY6hOld.png" alt="Instagram">
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>
</body>

</html>