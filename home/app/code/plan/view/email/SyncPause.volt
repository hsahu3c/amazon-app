<html>
  <head>
    <meta charset="utf-8" />
    <meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Plan Paused</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Roboto&display=swap"
      rel="stylesheet"
    />
    <style type="text/css">
      @import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");
      html,
      body {
        margin: 0 auto !important;
        padding: 0 !important;
        width: 100% !important;
        font-family: "Roboto", sans-serif;
        background-color: #f6f6f7;
      }
      /* Media Queries */

      @media screen and (max-width: 600px) {
        .email-container {
          width: 100% !important;
          margin: auto !important;
          padding: 22px 15px 0 !important;
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
        .width-unset {
          min-width: unset !important;
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
        .mob-padding1 {
          padding-bottom: unset !important;
        }
      }
    </style>
  </head>

  <body
    width="100%"
    style="margin: 0; text-align: center; background-color: #fff"
  >
    <table
      cellspacing="0"
      cellpadding="0"
      border="0"
      width="100%"
      bgcolor="#F6F6F7"
      align="center "
      style="
        font-family: 'Roboto', sans-serif;
        display: inline-block;
        max-width: 640px;
      "
    >
      <tbody>
        <tr>
          <td>
            <!-- email body starts -->
            <table
              cellspacing="0 "
              cellpadding="0 "
              border="0"
              width="640"
              height="100%"
              bgcolor="#F6F6F7 "
              class="email-container"
              style="margin: auto; padding: 32px 40px 0"
            >
              <tbody>
                <tr style="text-align: center">
                  <td style="display: inline-block; text-align: right">
                    <img src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/cedlogo.png" style="width: 100%;max-width: 24px;">
                  </td>
                  <td
                    style="
                      font-size: 16px;
                      line-height: 20px;
                      color: #202223;
                      display: inline-block;
                      font-weight: 400;
                      padding-left: 12px;
                    "
                    class="heading mob-paddding"
                  >
                    {{ app_name }}
                  </td>
                </tr>
                <tr>
                  <td
                    style="
                      font-size: 14px;
                      color: #e1e3e5;
                      display: block;
                      padding: 14px 40px 0;
                    "
                    class="line-break"
                  >
                    <span
                      style="display: block; border-top: 1px solid #ece9ff"
                    ></span>
                  </td>
                </tr>
              </tbody>
            </table>
          </td>
        </tr>
        <tr>
          <td>
            <table
              cellspacing="0 "
              cellpadding="0 "
              border="0 "
              width="640"
              bgcolor="#F6F6F7 "
              class="email-container"
              style="margin: auto; padding: 0px 40px"
            >
              <tbody>
                <tr style="text-align: center">
                  <td style="display: inline-block">
                    <table
                      style="padding: 20px 32px 20px; background-color: #f6f6f7"
                      class="import"
                    >
                      <tbody>
                        <tr style="text-align: center">
                          <td style="display: table-cell">
                            <img
                              src="https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/resume_sync.png"
                              style="width: 100%; max-width: 93px"
                            />
                          </td>
                        </tr>
                        <tr>
                          <td
                            style="
                              font-size: 26px;
                              line-height: 32px;
                              padding-top: 12px;
                              color: #202223;
                              text-align: center;
                            "
                          >
                          Order Syncing Paused – Action Required on {{ app_name }}
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>

                <tr
                  style="
                    background: #fff;
                    display: inline-block;
                    padding: 32px 0 0;
                    border-top-left-radius: 8px;
                    border-top-right-radius: 8px;
                  "
                >
                  <td
                    style="
                      font-size: 14px;
                      line-height: 20px;
                      color: #202223;
                      display: inline-block;
                      background-color: #fff;
                      padding: 0 32px;
                    "
                    class="mob-paddding"
                  >
                    <span
                      style="
                        display: block;
                        padding-bottom: 20px;
                        text-align: left;
                        font-weight: 600;
                      "
                      >Hello {{ name }},</span
                    >
                    <!-- <span
                      style="
                        display: block;
                        padding-bottom: 20px;
                        text-align: left;
                        font-weight: 600;
                      "
                      >{{ subject }}</span
                    > -->
                    <span
                      style="
                        text-align: left;
                        font-size: 14px;
                        line-height: 20px;
                        display: block;
                        font-family: 'Roboto', sans-serif;
                        color: #202223;
                        padding-bottom: 20px;
                      "
                      class="mob-padding1"
                    >
                      {% if has_settlement %} We hope this message finds you
                      well. We would like to remind you that your monthly excess
                      usage charges are due. As a result, your Amazon to Shopify
                      order syncing has been paused as you have exhausted your
                      credits. {% else %} We hope this message finds you well.
                      Unfortunately, you have exhausted all the credits in your
                      current plan. As a result, your order syncing from Amazon
                      to Shopify has been temporarily stopped. {% endif %}
                    </span>
                  </td>
                </tr>
                {% if has_settlement %}
                <table>
                  <tr>
                    <td
                      width="100%"
                      style="
                        background-color: #fff;
                        padding: 0px 32px 16px;
                        border-radius: 8px 8px 0 0;
                      "
                      class="mob-paddding"
                    >
                      <b>
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
                          Excess Usage Charge Details: :
                        </span>
                        <span
                          style="
                            text-align: left;
                            font-size: 14px;
                            line-height: 20px;
                            display: block;
                            font-family: 'Roboto', sans-serif;
                            color: #333333;
                            margin-top: 10px;
                          "
                        >
                          Excess Usage Charge Amount: ${{
                            settlement_invoice["settlement_amount"]
                          }}
                        </span>
                        <span
                          style="
                            text-align: left;
                            font-size: 14px;
                            line-height: 20px;
                            display: block;
                            font-family: 'Roboto', sans-serif;
                            color: #333333;
                            margin-top: 10px;
                          "
                        >
                          Credits Used: {{ settlement_invoice["credits_used"] }}
                        </span>
                      </b>
                      <span
                        style="
                          text-align: left;
                          font-size: 14px;
                          line-height: 20px;
                          display: block;
                          font-family: 'Roboto', sans-serif;
                          color: #333333;
                          margin-top: 10px;
                        "
                      >
                        To renew credits and resume your order syncing, please
                        pay your excess usage charges by clicking
                        <a href="{{ settlement_link }}">here</a>.
                      </span>
                      <span
                        style="
                          text-align: left;
                          font-size: 14px;
                          line-height: 20px;
                          display: block;
                          font-family: 'Roboto', sans-serif;
                          color: #333333;
                          margin-top: 10px;
                        "
                      >
                        Note: After payment, your current plan will resume with
                        fresh credits at the beginning of the next month.
                        However, if you want to reactivate order syncing now,
                        you can upgrade your plan.
                      </span>
                      <!-- <span style="text-align:left;font-size:14px;line-height:20px;display: block; font-family: 'Roboto', sans-serif;
                                color: #333333;margin-top: 10px;">
                                If you have any questions or concerns about your balance or payment options,
                                our
                                customer support team is available to assist you.
                            </span> -->
                    </td>
                  </tr>
                </table>
                {% else %}
                <tr style="text-align: left">
                  <td
                    style="
                      font-size: 14px;
                      line-height: 20px;
                      color: #202223;
                      display: block;
                      background-color: #fff;
                      padding: 0px 32px;
                      padding-bottom: 10px;
                    "
                    class="mob-paddding"
                  >
                    Here are two options to resume order syncing -
                  </td>
                </tr>
                <tr style="text-align: left">
                  <td
                    width="100%"
                    style="background-color: #fff; padding: 0 32px 0px"
                  >
                    <!-- <table width="440" style="margin: 0 auto">
                      <tbody width="100%">
                        <tr>
                          <td
                            style="
                              font-weight: 400;
                              font-size: 14px;
                              line-height: 20px;
                              color: #202223;
                              padding-bottom: 10px;
                            "
                          >
                            You can upgrade your plan immediately to enjoy
                            uninterrupted service. We recommend the following
                            plans as per your order credit usage -
                          </td>
                        </tr>
                      </tbody>
                    </table> -->
                    <table width="440" style="margin: 0 auto">
                      <tbody width="100%">
                        <tr>
                          <td style="
                          font-weight: 400;
                          font-size: 14px;
                          line-height: 20px;
                          color: #202223;
                          padding-top: 20px;
                        ">
                            1. You can upgrade your plan to enjoy uninterrupted service.
                            {% if has_recommended_plan_link %}
                            <table
                              cellspacing="0"
                              cellpadding="0"
                              width="100%"
                              style="border: 0; background-color: #f6f6f7"
                            >
                              <tr>
                                <td
                                  width="100%"
                                  style="background-color: #fff; padding: 0 32px 16px"
                                  class="mob-paddding"
                                >
                                  <table
                                    width="100%"
                                    style="
                                      background-color: #f1f8f5;
                                      border-radius: 8px;
                                    "
                                  >
                                    <tr>
                                      <td width="100%" style="padding: 20px 20px 0">
                                        <span
                                          style="
                                            font-weight: 600;
                                            font-size: 16px;
                                            line-height: 24px;
                                            margin-bottom: 4px;
                                            color: #202223;
                                            display: block;
                                          "
                                          >Upgrade Plan</span
                                        >
                                        {% if postpaid_enabled %}
                                          <span
                                          style="font-weight: 400; font-size: 14px; line-height: 20px; color: #202223;">Avoid
                                          Excess Usage Charge by upgrading to a more suitable plan. This
                                          offer is valid till no Excess Usage Charge amount is
                                          generated.</span>
                                          {% else %}
                                          <span
                                          style="font-weight: 400; font-size: 14px; line-height: 20px; color: #202223;">We recommend the following
                                          plans as per your order credit usage - </span>
                                          {% endif %}
                                        >
                                      </td>
                                    </tr>
                                    <tr>
                                      <td width="100%" style="padding: 20px">
                                        <table
                                          width="100%"
                                          style="
                                            border-radius: 8px;
                                            background-color: #fff;
                                          "
                                        >
                                          <tr>
                                            <td
                                              width="100%"
                                              colspan="2"
                                              style="padding: 16px 16px 0"
                                            >
                                              <span
                                                style="
                                                  font-weight: 600;
                                                  font-size: 16px;
                                                  line-height: 24px;
                                                  margin-right: 8px;
                                                  color: #202223;
                                                "
                                              >
                                                <b>{{ recommended_plan_name }}</b>
                                              </span>
                                              <span
                                                style="
                                                  font-weight: 400;
                                                  font-size: 12px;
                                                  line-height: 16px;
                                                  color: #202223;
                                                  background: #ffea8a;
                                                  border-radius: 3px;
                                                  padding: 2px 8px;
                                                "
                                                >Recommended</span
                                              >
                                            </td>
                                          </tr>
                                          {% for plan in recommended_plan %} {% if
                                          plan['plan_data']['billed_type'] == 'monthly'
                                          %}
                                          <tr>
                                            <td style="padding: 18px 0 18px 16px">
                                              <span
                                                style="
                                                  font-weight: 600;
                                                  font-size: 14px;
                                                  line-height: 20px;
                                                  display: block;
                                                  color: #202223;
                                                  margin-bottom: 4px;
                                                "
                                                >$
                                                {{
                                                  plan["plan_data"]["custom_price"]
                                                }}/mo
                                              </span>
                                              <span
                                                style="
                                                  font-weight: 400;
                                                  font-size: 14px;
                                                  line-height: 20px;
                                                  display: block;
                                                  color: #202223;
                                                "
                                                >Manage
                                                {{
                                                  plan["plan_data"]["description"]
                                                }}
                                                per month</span
                                              >
                                            </td>
                                            <td
                                              style="
                                                padding-right: 16px;
                                                text-align: right;
                                              "
                                            >
                                              <button
                                                type="button"
                                                style="
                                                  background: #008060;
                                                  border: 1px solid #008060;
                                                  box-shadow: 0px 1px 0px
                                                    rgba(0, 0, 0, 0.05);
                                                  border-radius: 4px;
                                                  padding: 8px 16px;
                                                "
                                              >
                                                <a
                                                  href="{{ plan['link'] }}"
                                                  style="
                                                    font-weight: 500;
                                                    font-size: 14px;
                                                    line-height: 20px;
                                                    text-decoration: none;
                                                    color: #fff;
                                                  "
                                                  >Pay
                                                  {{
                                                    plan["plan_data"]["billed_type"]
                                                  }}</a
                                                >
                                              </button>
                                            </td>
                                          </tr>
                                          {% endif %} {% if
                                          plan['plan_data']['billed_type'] == 'yearly'
                                          %}
                                          <tr>
                                            <td
                                              width="100"
                                              colspan="2"
                                              style="padding: 0 16px"
                                            >
                                              <hr
                                                style="
                                                  margin: 0;
                                                  border-top: 1px solid #e1e3e5;
                                                "
                                              />
                                            </td>
                                          </tr>
                                          <tr>
                                            <td style="padding: 18px 0 18px 16px">
                                              {% if plan['plan_data']['montly_price'] !=
                                              plan['plan_data']['monthly_discounted_price']
                                              %}
                                              <span
                                                style="
                                                  font-weight: 400;
                                                  font-size: 14px;
                                                  line-height: 20px;
                                                  color: #6d7175;
                                                  margin-bottom: 4px;
                                                  text-decoration: line-through;
                                                "
                                                >$
                                                {{
                                                  plan["plan_data"]["montly_price"]
                                                }}</span
                                              >
                                              {% endif %}
                                              <span
                                                style="
                                                  font-weight: 600;
                                                  font-size: 14px;
                                                  line-height: 20px;
                                                  color: #202223;
                                                  margin-bottom: 4px;
                                                "
                                                >$
                                                {{
                                                  plan["plan_data"][
                                                    "monthly_discounted_price"
                                                  ]
                                                }}/mo</span
                                              >
                                              <span
                                                style="
                                                  font-weight: 700;
                                                  font-size: 14px;
                                                  line-height: 20px;
                                                  display: block;
                                                  color: #347c84;
                                                  margin: 4px 0;
                                                "
                                                >(Billed at ${{
                                                  plan["plan_data"]["discounted_price"]
                                                }}
                                                per year )</span
                                              >
                                              <span
                                                style="
                                                  font-weight: 400;
                                                  font-size: 14px;
                                                  line-height: 20px;
                                                  display: block;
                                                  color: #202223;
                                                "
                                                >Manage
                                                {{
                                                  plan["plan_data"]["description"]
                                                }}
                                                per month</span
                                              >
                                            </td>
                                            <td
                                              style="
                                                padding-right: 16px;
                                                text-align: right;
                                              "
                                            >
                                              <button
                                                type="button"
                                                style="
                                                  background: #008060;
                                                  border: 1px solid #008060;
                                                  box-shadow: 0px 1px 0px
                                                    rgba(0, 0, 0, 0.05);
                                                  border-radius: 4px;
                                                  padding: 8px 16px;
                                                "
                                              >
                                                <a
                                                  href="{{ plan['link'] }}"
                                                  style="
                                                    font-weight: 500;
                                                    font-size: 14px;
                                                    line-height: 20px;
                                                    text-decoration: none;
                                                    color: #fff;
                                                  "
                                                >
                                                  Pay
                                                  {{ plan["plan_data"]["billed_type"] }}
                                                </a>
                                              </button>
                                            </td>
                                          </tr>
                                          {% endif %} {% endfor %}
                                        </table>
                                      </td>
                                    </tr>
                                  </table>
                                </td>
                              </tr>
                            </table>
                            {% endif %}
                          </td>
                        </tr>
                        <tr>
                          <td
                            style="
                              font-weight: 400;
                              font-size: 14px;
                              line-height: 20px;
                              color: #202223;
                              padding-top: 20px;
                            "
                          >
                            2. You can wait until the beginning of next month
                            when your plan renews and order sync is reactivated.
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
                {% endif %}
                <tr style="text-align: left">
                  <td
                    style="
                      font-size: 14px;
                      line-height: 20px;
                      color: #202223;
                      display: block;
                      background-color: #fff;
                      padding: 20px 32px;
                    "
                    class="mob-paddding"
                  >
                    For more details or assistance feel free to contact our
                    customer support team.
                  </td>
                </tr>
                <tr style="text-align: left">
                  <td
                    style="
                      font-size: 14px;
                      line-height: 20px;
                      color: #202223;
                      display: block;
                      background-color: #fff;
                      padding: 0px 32px;
                    "
                    class="mob-paddding"
                  >
                    Thank you for choosing CedCommerce.
                  </td>
                </tr>
                <tr style="text-align: left">
                  <td
                    style="
                      font-size: 14px;
                      line-height: 20px;
                      color: #202223;
                      background-color: #fff;
                      padding: 0px 32px 24px;
                    "
                    class="mob-paddding"
                  >
                    <a href="{{ support_page }}" color="#2C6ECB">Contact Us</a>.
                  </td>
                </tr>
                <tr style="text-align: left">
                  <td
                    style="
                      font-size: 14px;
                      line-height: 20px;
                      color: #202223;
                      display: block;
                      background-color: #fff;
                      border-radius: 0px 0px 8px 8px;
                      padding: 0px 32px 32px;
                    "
                    class="mob-paddding"
                  >
                    Regards, <br />
                    <span style="font-weight: 600">Team CedCommerce</span><br />
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
            <table
              cellspacing="0 "
              cellpadding="0 "
              border="0"
              width="640 "
              bgcolor="#F6F6F7 "
              class="email-container"
              style="
                margin: auto;
                padding: 20px 40px 20px;
                color: #6d7175;
                line-height: 20px;
                font-size: 14px;
              "
            >
              <tbody>
                <tr>
                  <td
                    style="
                      font-size: 14px;
                      color: #e1e3e5;
                      display: block;
                      padding: 0px 10px 0;
                    "
                    class="mob-paddding"
                  >
                    <span
                      style="display: block; border-top: 1px solid #ece9ff"
                    ></span>
                  </td>
                </tr>
              </tbody>
            </table>
          </td>
        </tr>
        <tr>
          <td>
            <table
              cellspacing="0"
              cellpadding="0"
              width="100%"
              style="background-color: #f6f6f7; border: 0"
            >
              <tr>
                <td
                  width="100%"
                  style="
                    font-size: 14px;
                    color: #e1e3e5;
                    padding-top: 20px;
                    padding-bottom: 20px;
                  "
                  class="mob-paddding"
                >
                  <span
                    style="display: block; border-top: 1px solid #ece9ff"
                  ></span>
                </td>
              </tr>
            </table>
            <table
              cellspacing="0"
              cellpadding="0"
              width="100%"
              class="pt-0"
              style="
                border: 0;
                background-color: #f6f6f7;
                margin-left: auto;
                margin-right: auto;
              "
            >
              <tr>
                <td
                  width="100%"
                  style="font-size: 14px; text-align: center; color: #616771"
                >
                  <img
                    src="https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/cedlogo.png"
                    style="width: 100%; max-width: 24px"
                  />
                </td>
              </tr>
              <tr>
                <td
                  width="100%"
                  style="
                    color: #262626;
                    line-height: 17px;
                    padding-top: 16px;
                    text-align: center;
                  "
                >
                  Team CedCommerce
                </td>
              </tr>
              <tr>
                <td
                  width="100%"
                  style="
                    color: #262626;
                    line-height: 17px;
                    padding-top: 8px;
                    text-align: center;
                    font-size: 10px;
                    max-width: 230px;
                  "
                >
                  CedCommerce Inc. 1B12 N Columbia Blvd Suite C15-653026
                  Portland, Oregon, 97217, USA
                </td>
              </tr>
              <tr>
                <td
                  width="100%"
                  style="
                    color: #262626;
                    line-height: 17px;
                    padding-top: 10px;
                    padding-bottom: 48px;
                    text-align: center;
                  "
                >
                  <a
                    href="https://www.facebook.com/CedCommerce/"
                    title="fb"
                    style="text-decoration: none"
                  >
                    <img
                      src="https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/fb.png"
                    />
                  </a>
                  <a
                    href="https://twitter.com/cedcommerce/"
                    title="twitter"
                    style="padding-left: 20px; text-decoration: none"
                  >
                    <img
                      src="https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/x.png"
                    />
                  </a>
                  <a
                    href="https://www.instagram.com/CedCommerce/"
                    title="social-insta"
                    style="padding-left: 20px; text-decoration: none"
                  >
                    <img
                      src="https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/insta.png"
                    />
                  </a>
                  <a
                    href="https://www.linkedin.com/company/cedcommerce"
                    title="linkedin"
                    style="padding-left: 20px; text-decoration: none"
                  >
                    <img
                      src="https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/linkedin.png"
                    />
                  </a>
                  <a
                    href="https://www.youtube.com/channel/UCLRUCC_jvKf4tfZ2omjaW8Q"
                    title="youtube"
                    style="padding-left: 20px; text-decoration: none"
                  >
                    <img
                      src="https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/yt.png"
                    />
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </tbody>
    </table>
  </body>
</html>
