<html>

<head>
    <meta charset="utf-8" />
    <meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo $t1;?></title>
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
                                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png"
                                                        style="width: 100%;max-width: 24px;">
                                                </td>
                                                <td width="65%"
                                                    style="font-size: 16px;line-height: 20px;color: #202223;font-weight: 400; padding-top: 34px;   padding-left: 12px; text-align: left;"
                                                    class="heading">
                                                    <?php echo $t2;?>
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
                                                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/failed_shipment.png">
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="100%"
                                                    style="font-size:26px;line-height:32px;padding-top: 12px;padding-bottom: 20px; color: #333333;text-align: center">
                                                    <?php echo $t1;?></td>
                                            </tr>

                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333; background-color: #fff; padding:32px 32px 16px;border-radius: 8px 8px 0 0;"
                                        class="mob-paddding">
                                        <span
                                            style="display:block;padding-bottom: 30px;text-align: left;font-weight: 600;"><?php echo $t3;?> 
                                            {{ name }}, </span>
                                        <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                            color: #333333;">
                                            <?php echo $t4;?> <?= ucfirst($target_marketplace) ?> <?php echo $t5;?> <?= ucfirst($source_marketplace) ?>.
                                        </span>

                                        <span
                                            style="font-size: 14px; line-height: 20px; display: block; color: #333333;  background-color: #fff; padding-top: 10px;"
                                            class="mob-paddding">
                                            <?php echo $t6;?>
                                        </span>
                                        <ul>
                                            <li><?php echo $t7;?>
                                            </li>
                                            <li><?php echo $t8;?> {% if has_order_grid_link %} <?php echo $t9;?> <a href="{{ order_grid_link }}"><?php echo $t10;?></a> {% endif %}
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </table>
                            <table cellspacing="0" cellpadding="0" width="100%"
                                style="border: 0; background-color: #f6f6f7;">
                                <tr>
                                    <td width="100%" style="background-color: #fff; padding: 0px 32px 24px; "
                                        class="mob-paddding ">
                                        <table cellspacing="0" cellpadding="0" style="border: 1px solid #eee;">
                                            <tr>
                                                <td
                                                    style="font-size:12px;font-weight:600;line-height:20px; padding: 10px;">
                                                    <?= ucfirst($target_marketplace) ?> <?php echo $t11;?></td>
                                                <td
                                                    style="font-size:12px;font-weight:600;line-height:20px; padding: 10px;">
                                                    <?= ucfirst($source_marketplace) ?> <?php echo $t11;?></td>
                                                <td
                                                    style="font-size:12px;font-weight:600;line-height:20px; padding: 10px;">
                                                    <?php echo $t12;?></td>
                                            </tr>
                                            <tr>
                                                <td style="border-bottom: 1px solid #eee;"></td>
                                                <td style="border-bottom: 1px solid #eee;"></td>
                                                <td style="border-bottom: 1px solid #eee;"></td>
                                            </tr>
                                            {% for finalData in userData %}
                                            <tr>
                                                <td style="font-size:12px;padding:10px;line-height: 20px;">
                                                    {{ finalData['target'] }}</td>
                                                <td style="font-size:12px;padding:10px;line-height: 20px;">
                                                    {{ finalData['source'] }}</td>
                                                <td style="font-size:12px;padding:10px;line-height: 20px;">
                                                    {{ finalData['reason'] }}</td>
                                            </tr>
                                            {% endfor %}
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table cellspacing="0" cellpadding="0" width="100%"
                                style="border: 0; background-color: #F6F6F7;">
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333;  background-color: #fff; padding: 10px 32px 0px;"
                                        class="mob-paddding">
                                        <?php echo $t13;?> <a
                                            href="mailto:channel-support@cedcommerce.com" color="#2C6ECB"><?php echo $t14;?></a>.
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="font-size: 14px; line-height: 20px; color: #333333; background-color: #fff; border-radius: 0px 0px 8px 8px; padding: 0px 32PX 32px;"
                                        class="mob-paddding">
                                        <br /><?php echo $t15;?><br />
                                        <span style="font-weight: 600;"><?php echo $t16;?></span>
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
                                        <?php echo $t16;?>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%"
                                        style="color:#262626;line-height:17px;padding-top:8px;text-align:center;font-size: 10px;max-width: 230px;">
                                        CedCommerce Inc. 1B12 N Columbia Blvd Suite C15-653026 Portland, Oregon,
                                        97217, USA
                                    </td>
                                </tr>
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