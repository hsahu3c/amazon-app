<html>

<head>
  <meta charset="utf-8" />
  <meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
  <meta name="x-apple-disable-message-reformatting" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Plan Deactivation</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet" />
  <style type="text/css">
    @import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");

    html,
    body {
      margin: 0 auto !important;
      padding: 0 !important;
      width: 100% !important;
      font-family: "Roboto", sans-serif;
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

<body width="100%" style="margin: 0; background-color: #fff">
  <table cellspacing="0" cellpadding="0" width="100%" style="margin-left: auto; margin-right: auto">
    <tr>
      <td width="100%">
        <table class="email-container" max-width="100%" cellspacing="0" cellpadding="0" width="600" style="
              margin-left: auto;
              margin-right: auto;
              background-color: #eeeeee;
            ">
          <tr>
            <td width="100%" class="mob-paddding" style="
                  padding-left: 20px;
                  padding-right: 20px;
                  background: #f6f6f7;
                ">
              <!-- email body starts -->
              <table cellspacing=" 0" cellpadding="0" width="100%" height="100%" style="
                    margin-left: auto;
                    margin-right: auto;
                    background-color: #f6f6f7;
                    border: 0;
                  ">
                <tr>
                  <td width="100%">
                    <table cellspacing="0" cellpadding="0" style="background-color: #f6f6f7; width: 100%">
                      <tr>
                        <td style="text-align: center; padding-top: 34px" width="100%">
                            <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png" style="
                            width: 100%;
                            max-width: 24px;
                            vertical-align: middle;
                          " />
                          <span style="
                                font-size: 16px;
                                line-height: 20px;
                                color: #202223;
                                font-weight: 400;
                                padding-left: 12px;
                                display: inline-block;
                                vertical-align: middle;
                              ">{{ app_name }}</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td width="100%" style="
                        font-size: 14px;
                        color: #e1e3e5;
                        padding-top: 14px;
                        padding-bottom: 20px;
                      " class="line-break">
                    <span style="display: block; border-top: 1px solid #ece9ff"></span>
                  </td>
                </tr>
              </table>
              <!-- email header ends -->
              <!-- email content starts -->
              <table cellspacing="0" cellpadding="0" width="100%" style="border: 0; background-color: #f6f6f7">
                <tr>
                  <td width="100%">
                    <table style="
                          background-color: #f6f6f7;
                          margin-left: auto;
                          margin-right: auto;
                        " width="100%" class="import">
                      <tr>
                        <td width="100%" style="text-align: center">
                          <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/warning.png" />
                        </td>
                      </tr>
                      <tr>
                        <td width="100%" style="
                              font-size: 26px;
                              line-height: 32px;
                              padding-top: 12px;
                              padding-bottom: 20px;
                              color: #333333;
                              text-align: center;
                            ">
                          Plan Deactivation
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td width="100%" style="
                        font-size: 14px;
                        line-height: 20px;
                        color: #333333;
                        background-color: #fff;
                        border-radius: 8px 8px 0 0;
                        padding-top: 32px;
                        padding-left: 32px;
                        padding-right: 32px;
                        padding-bottom: 16px;
                      " class="mob-paddding">
                    <span style="
                          display: block;
                          padding-bottom: 30px;
                          text-align: left;
                          font-weight: 600;
                        ">Hello {{ name }},
                    </span>
                    <span style="
                          text-align: left;
                          font-size: 14px;
                          line-height: 20px;
                          display: block;
                          font-family: 'Roboto', sans-serif;
                          color: #333333;
                          padding-bottom: 16px;
                        ">
                      I hope this message finds you well. 
                    </span>
                    <span style="
                          text-align: left;
                          font-size: 14px;
                          line-height: 20px;
                          display: block;
                          font-family: 'Roboto', sans-serif;
                          color: #333333;
                          padding-bottom: 16px;
                        ">
                      We are writing to inform you that your Current
                      Subscription plan -
                      <b>({{ plan_details["title"] }} - ${{
                        plan_details["custom_price"]
                        }}
                        with {{ plan_details["description"] }})</b>
                      is about to expire {% if last_day %} <b>tomorrow</b>{%
                      endif %}. To ensure uninterrupted access to our services
                      and to continue enjoying the benefits of our app, we
                      kindly request you to upgrade your plan.
                    </span>
                    <span style="
                          text-align: left;
                          font-size: 14px;
                          line-height: 20px;
                          display: block;
                          font-family: 'Roboto', sans-serif;
                          color: #333333;
                          padding-bottom: 16px;
                        ">
                      Please note that your current plan will expire on
                      <b>{{ deactivate_on }}</b>, and it is essential to upgrade before that date to
                      avoid any disruptions in service.
                    </span>
                    <span style="
                          text-align: left;
                          font-size: 14px;
                          line-height: 20px;
                          display: block;
                          font-family: 'Roboto', sans-serif;
                          color: #333333;
                        ">
                      To facilitate the upgrade process and select the plan
                      that best suits your requirements, please follow these
                      simple steps:
                    </span>
                  </td>
                </tr>
              </table>
              <table cellspacing="0" cellpadding="0" width="100%" style="border: 0; background-color: #f6f6f7">
                <tr>
                  <td width="100%" style="
                        font-size: 14px;
                        line-height: 20px;
                        color: #333333;
                        background-color: #fff;
                        padding-left: 32px;
                        padding-right: 32px;
                      " class="mob-paddding">
                    <ol>
                      <li>
                        <span style="
                              text-align: left;
                              font-size: 14px;
                              line-height: 20px;
                              display: block;
                              font-family: 'Roboto', sans-serif;
                              color: #333333;
                              font-weight: 600;
                            ">
                          Log in to your {{ app_name }} app.
                        </span>
                      </li>
                      <li>
                        <span style="
                              text-align: left;
                              font-size: 14px;
                              line-height: 20px;
                              display: block;
                              font-family: 'Roboto', sans-serif;
                              color: #333333;
                              font-weight: 600;
                            ">
                          Navigate to subscription and click on
                          <span style="color: #08090a">‘View all plans’.</span>
                        </span>
                      </li>
                      <li>
                        <span style="
                              text-align: left;
                              font-size: 14px;
                              line-height: 20px;
                              display: block;
                              font-family: 'Roboto', sans-serif;
                              color: #333333;
                              font-weight: 600;
                            ">
                          Choose the plan based on your business requirements
                          and confirm. 
                        </span>
                      </li>
                    </ol>
                  </td>
                </tr>
                <!-- <tr>
                    <td
                      width="100%"
                      style="
                        font-size: 14px;
                        line-height: 20px;
                        color: #333333;
                        background-color: #fff;
                        padding-left: 32px;
                        padding-right: 32px;
                        padding-bottom: 4px;
                      "
                      class="mob-paddding"
                    >
                      <span
                        style="
                          text-align: left;
                          font-size: 14px;
                          line-height: 20px;
                          display: block;
                          font-family: 'Roboto', sans-serif;
                          color: #333333;
                        "
                      >
                        What are you waiting for?
                      </span>
                    </td>
                  </tr> -->
                <!-- <tr>
                    <td
                      width="100%"
                      style="
                        font-size: 14px;
                        line-height: 20px;
                        color: #333333;
                        background-color: #fff;
                        padding-left: 32px;
                        padding-right: 32px;
                        padding-bottom: 16px;
                      "
                      class="mob-paddding"
                    >
                      <a
                        href="#"
                        style="
                          border-radius: 9px;
                          box-shadow: 0px 1px 0px 0px #000 inset,
                            0px -1px 0px 1px #000 inset,
                            -2px 0px 0px 0px rgba(255, 255, 255, 0.2) inset,
                            2px 0px 0px 0px rgba(255, 255, 255, 0.2) inset,
                            0px 2px 0px 0px rgba(255, 255, 255, 0.2) inset;
                          display: inline-block;
                          padding: 5px 10px;
                          background-color: #000;
                          text-decoration: unset;
                          color: fff;
                        "
                        >Upgrade Plan Today</a
                      >
                    </td>
                  </tr> -->
              </table>
              <table cellspacing="0" cellpadding="0" width="100%" style="border: 0; background-color: #f6f6f7">
                <tr>
                  <td width="100%" style="
                        font-size: 14px;
                        line-height: 20px;
                        color: #333333;
                        background-color: #fff;
                        padding-top: 0px;
                        padding-left: 32px;
                        padding-right: 32px;
                        padding-bottom: 0px;
                      " class="mob-paddding">
                    If you have any questions or require assistance with the
                    upgrade process, please do not hesitate to reach out to
                    our customer support team at:
                    <br /><a href="{{ support_page }}" color="#2C6ECB">Here</a>.
                  </td>
                </tr>
                <tr>
                  <td width="100%" style="
                        font-size: 14px;
                        line-height: 20px;
                        color: #333333;
                        background-color: #fff;
                        border-radius: 0px 0px 8px 8px;
                        padding-top: 0px;
                        padding-left: 32px;
                        padding-right: 32px;
                        padding-bottom: 32px;
                      " class="mob-paddding">
                    <br />
                    Regards, <br />
                    <span style="font-weight: 600">Team CedCommerce</span>
                  </td>
                </tr>
              </table>

              <!-- email footer starts -->
              <!-- <table cellspacing="0" cellpadding="0" width="100%" style="
                    background-color: #f6f6f7;
                    border: 0;
                    margin-left: auto;
                    margin-right: auto;
                  ">
                <tr>
                  <td width="100%" style="
                        font-size: 14px;
                        text-align: center;
                        line-height: 20px;
                        color: #616771;
                        padding-top: 20px;
                        padding-bottom: 0px;
                        padding-left: 32px;
                        padding-right: 32px;
                      ">
                    If you need any assistance, check out the extensive
                    <a href="#" color="#2C6ECB">self-help section </a>
                    for quick resolution. If you’d rather not receive this
                    kind of email, you can
                    <a href="#" color="#2C6ECB">
                      unsubscribe or manage your email preferences.</a>
                  </td>
                </tr>
              </table> -->
              <table cellspacing="0" cellpadding="0" width="100%" style="background-color: #f6f6f7; border: 0">
                <tr>
                  <td width="100%" style="
                        font-size: 14px;
                        color: #e1e3e5;
                        padding-top: 20px;
                        padding-bottom: 20px;
                      " class="mob-paddding">
                    <span style="display: block; border-top: 1px solid #ece9ff"></span>
                  </td>
                </tr>
              </table>
              <table cellspacing="0" cellpadding="0" width="100%" class="pt-0" style="
                    border: 0;
                    background-color: #f6f6f7;
                    margin-left: auto;
                    margin-right: auto;
                  ">
                <tr>
                  <td width="100%" style="
                        font-size: 14px;
                        text-align: center;
                        color: #616771;
                      ">
                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png" style="width: 100%; max-width: 24px" />
                  </td>
                </tr>
                <tr>
                  <td width="100%" style="
                        color: #262626;
                        line-height: 17px;
                        padding-top: 16px;
                        text-align: center;
                      ">
                    Team CedCommerce
                  </td>
                </tr>
                <tr>
                  <td width="100%" style="
                        color: #262626;
                        line-height: 17px;
                        padding-top: 8px;
                        text-align: center;
                        font-size: 10px;
                        max-width: 230px;
                      ">
                    CedCommerce Inc. 1B12 N Columbia Blvd Suite C15-653026
                    Portland, Oregon, 97217, USA
                  </td>
                </tr>
                <tr>
                  <td width="100%" style="
                        color: #262626;
                        line-height: 17px;
                        padding-top: 10px;
                        padding-bottom: 48px;
                        text-align: center;
                      ">
                    <a href="https://www.facebook.com/CedCommerce/" title="fb" style="text-decoration: none">
                      <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/fb.png" />
                    </a>
                    <a href="https://twitter.com/cedcommerce/" title="twitter"
                      style="padding-left: 20px; text-decoration: none">
                      <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/x.png" />
                    </a>
                    <a href="https://www.instagram.com/CedCommerce/" title="social-insta"
                      style="padding-left: 20px; text-decoration: none">
                      <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/insta.png" />
                    </a>
                    <a href="https://www.linkedin.com/company/cedcommerce" title="linkedin"
                      style="padding-left: 20px; text-decoration: none">
                      <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/linkedin.png" />
                    </a>
                    <a href="https://www.youtube.com/channel/UCLRUCC_jvKf4tfZ2omjaW8Q" title="youtube"
                      style="padding-left: 20px; text-decoration: none">
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