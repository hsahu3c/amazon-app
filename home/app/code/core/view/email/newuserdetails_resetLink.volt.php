<!DOCTYPE html>
<html lang="en">

<head>
  <title></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <style type="text/css">
    /* CLIENT-SPECIFIC STYLES */
    body,
    table,
    td,
    a {
      -webkit-text-size-adjust: 100%;
      -ms-text-size-adjust: 100%;
    }

    /* Prevent WebKit and Windows mobile changing default text sizes */
    table,
    td {
      mso-table-lspace: 0pt;
      mso-table-rspace: 0pt;
    }

    /* Remove spacing between tables in Outlook 2007 and up */
    img {
      -ms-interpolation-mode: bicubic;
    }

    /* Allow smoother rendering of resized image in Internet Explorer */

    /* RESET STYLES */
    img {
      border: 0;
      height: auto;
      line-height: 100%;
      outline: none;
      text-decoration: none;
    }

    table {
      border-collapse: collapse !important;
    }

    body {
      height: 100% !important;
      margin: 0 !important;
      padding: 0 !important;
      width: 100% !important;
    }

    /* iOS BLUE LINKS */
    a[x-apple-data-detectors] {
      color: inherit !important;
      text-decoration: none !important;
      font-size: inherit !important;
      font-family: inherit !important;
      font-weight: inherit !important;
      line-height: inherit !important;
    }

    /* MOBILE STYLES */
    @media screen and (max-width: 600px) {
      td[class="text-center"] {
        text-align: center !important;
      }

      /* ALLOWS FOR FLUID TABLES */
      .wrapper {
        width: 100% !important;
        max-width: 100% !important;
      }

      /* ADJUSTS LAYOUT OF LOGO IMAGE */
      .logo img {
        margin: 0 auto !important;
      }

      /* USE THESE CLASSES TO HIDE CONTENT ON MOBILE */
      .mobile-hide {
        display: none !important;
      }

      .img-max {
        max-width: 100% !important;
        width: 100% !important;
        height: auto !important;
      }

      /* FULL-WIDTH TABLES */
      table[class=responsive-table] {
        width: 100% !important;
      }

      /* UTILITY CLASSES FOR ADJUSTING PADDING ON MOBILE */
      .padding {
        padding: 10px 5% 15px 5% !important;
      }

      .padding-meta {
        padding: 30px 5% 0px 5% !important;
        text-align: center;
      }

      .padding-copy {
        padding: 10px 5% 10px 5% !important;
        text-align: center;
      }

      .no-padding {
        padding: 0 !important;
      }

      .section-padding {
        padding: 15px 15px 15px 15px !important;
      }

      /* ADJUST BUTTONS ON MOBILE */
      .mobile-button-container {
        margin: 0 auto;
        width: 100% !important;
      }

      .mobile-button {
        padding: 12px 30px !important;
        border: 0 !important;
        font-size: 16px !important;
        display: block !important;
      }
    }

    /* ANDROID CENTER FIX */
    div[style*="margin: 16px 0;"] {
      margin: 0 !important;
    }
  </style>
  <!--[if gte mso 12]>
<style type="text/css">
.mso-right {
	padding-left: 20px;
}
</style>
<![endif]-->
</head>

<body style="margin: 0 !important; padding: 0 !important;">

  <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 0; padding: 0;">
    <tr>
      <td align="center" valign="top">
        <!--[if (gte mso 9)|(IE)]>
      <table align="center" border="0" cellspacing="0" cellpadding="0" width="500">
      <tr>
      <td align="center" valign="top" width="500">
      <![endif]-->
        <table border="0" cellpadding="0" cellspacing="0" width="600" class="responsive-table">
          <tr>
            <td align="center" valign="top">
              <table width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center">
                    <img style="display: block;" src="<?= $banner ?>" width="600" border="0" alt="Banner Image" class="img-max">
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
        <!--[if (gte mso 9)|(IE)]>
      </td>
      </tr>
      </table>
      <![endif]-->
      </td>
    </tr>
  </table>


  <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 0; padding: 0;">
    <tr>
      <td align="center" valign="top">
        <!--[if (gte mso 9)|(IE)]>
      <table align="center" border="0" cellspacing="0" cellpadding="0" width="500">
      <tr>
      <td align="center" valign="top" width="500">
      <![endif]-->
        <table border="0" cellpadding="0" cellspacing="0" width="600" class="responsive-table">
          <tr>
            <td align="center" bgcolor="#fafafa" style="padding: 15px 15px 15px 15px;">
              <table width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td valign="top" height="20"></td>
                </tr>
                <tr>
                  <td align="left">
                    <font face="'Open Sans', Arial, Helvetica, sans-serif;">
                      <span style="color: #333333;font-size: 24px;">Dear <?= $username ?>,</span>
                    </font>
                  </td>
                </tr>
                <tr>
                  <td valign="top" height="10"></td>
                </tr>
                <tr>
                  <td align="left">
                    <font face="'Open Sans', Arial, Helvetica, sans-serif;">
                      <span style="color: #6DCC00;font-size: 14px;"> Greetings! </span>
                    </font>
                  </td>
                </tr>
                <tr>
                  <td valign="top" height="10"></td>
                </tr>
                <tr>
                  <td align="left">
                    <font face="'Open Sans', Arial, Helvetica, sans-serif;">
                      <span style="color: #000000;font-size: 16px;"> You Account Details are : </span>
                    </font>
                  </td>
                </tr>
                <tr>
                  <td valign="top" height="10"></td>
                </tr>
                <tr>
                  <td align="left">
                    <font face="'Open Sans', Arial, Helvetica, sans-serif;">
                      <span style="color: #000000;font-size: 13px;"> <strong>Username :</strong> <?= $username ?> </span>
                    </font>
                  </td>
                </tr>
                <tr>
                  <td valign="top" height="7"></td>
                </tr>
                <tr>
                  <td align="left">
                    <font face="'Open Sans', Arial, Helvetica, sans-serif;">
                      <span style="color: #000000;font-size: 13px;"> <strong>Email :</strong> <?= $email ?> </span>
                    </font>
                  </td>
                </tr>
                <tr>
                  <td valign="top" height="7"></td>
                </tr>

                <tr>
                  <td valign="top" height="20"></td>
                </tr>
                <tr>
                  <td align="left">
                    <font face="'Open Sans', Arial, Helvetica, sans-serif;">
                      <span style="color: #6DCC00;font-size: 14px;"> For account security, change your password as soon as possible. </span>
                    </font>
                  </td>
                </tr>
                <tr>
                  <td align="left">
                    <font face="'Open Sans', Arial, Helvetica, sans-serif;">
                      <span style="color: #666;font-size: 14px;line-height: 24px;"><a href="<?= $link ?>">Click here</a> to reset your password.</span>
                    </font>
                  </td>
                </tr>
                <tr>
                  <td valign="top" height="20"></td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
        <!--[if (gte mso 9)|(IE)]>
      </td>
      </tr>
      </table>
      <![endif]-->
      </td>
    </tr>
  </table>


  <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 0; padding: 0;">
    <tr>
      <td align="center" valign="top">
        <!--[if (gte mso 9)|(IE)]>
      <table align="center" border="0" cellspacing="0" cellpadding="0" width="500">
      <tr>
      <td align="center" valign="top" width="500">
      <![endif]-->
        <table border="0" cellpadding="0" cellspacing="0" width="600" class="responsive-table">
          <tr>
            <td align="center" bgcolor="#333" valign="top" style="padding: 15px 15px 15px 15px;">
              <table width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center" valign="top">
                    <table border="0" cellpadding="0" cellpadding="0" width="350" class="responsive-table" align="left">
                      <tr>
                        <td align="center" valign="top">
                          <table width="100%" border="0" cellspacing="0" cellpadding="0">
                            <tr>
                              <td valign="top" height="10"></td>
                            </tr>
                            <tr>
                              <td align="left" valign="top" class="text-center">
                                <font face="'Open Sans', Arial, Helvetica, sans-serif;">
                                  <span style="display: block;color: #fff;font-size: 14px;line-height: 24px;">© 2018 Cedcommerce All rights reserved.</span>
                                </font>
                              </td>
                            </tr>
                            <tr>
                              <td valign="top" height="10"></td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>

                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
        <!--[if (gte mso 9)|(IE)]>
      </td>
      </tr>
      </table>
      <![endif]-->
      </td>
    </tr>
  </table>

</body>

</html>