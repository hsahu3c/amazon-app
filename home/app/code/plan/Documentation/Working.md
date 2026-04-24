# Plan Working

> Recommendations

## How the recommendations are generated?
<details><summary>See here</summary>

```
    The recommendations are generated in 3 ways:
    1. When client comes at the very first time on app we will fetch its historical data from the amazon and calculate its order count and make a decision on the basis of that count
    2. If client has any previous month monthly_usage we will make a recommendation based on its previous usage
    3. If client has no previous usage but has some usage in the plan we will calculate the recommendations based on a predicted order count which will be calculated as follows:
        orderCount = totalUsedCreditsThisMonth / planActiveForDays * 30;

```
</details>

## When the recommendations will be visible?
<details><summary>See here</summary>

```
    1. When client is new and has no active plan
    2. After 10 days of plan activation based on the above provided ways
    3. If client's usage is more than 70% before 10 days
    4. Only is client has any credit usage

```
</details>


## Priority in historical and app usage recommendations?
<details><summary>See here</summary>

```
    1. The historical recommendations will be only visible for 45 days after the client's arrival and plan activation
    2. The priority is decided by the most highest plan among both recommendations

```
</details>

---

> Execess Usage Generation

## When is excess usage generated?

<details><summary>See here</summary>

```
   If active_plan's complete prepaid limits have been exhausted and plan supports postpaid credits then its excess usage charge will be generated based on each credit exhausted till the credits used completely or till the last payment date reached.

```
</details>

## What happens if the excess usage charge is pending and plan needs to be renew?

<details><summary>See here</summary>

```
   We have conditions in the plan that if the plan needs to be renew or deactivated and the client has any pending settlement then the process will not be proceeded till the client will not pay their usage.

```
</details>

---

> Plan restirictions

## What are the limitations of free plan in the plan module?

<details><summary>See here</summary>

```
   1. User cannot downgrade to a free plan by itself once moving to a paid plan
   2. User will be restricted to choose free plan at the very beginning after the account connection and its product importing will be halted if the product count of the client is more than the allowed number of limit for a free plan.
   3. Offered postpaid credit limit is also less in free plan i.e., 10 credits are offered.

```
</details>

---

> Plan restorations

## What are the conditions of plan restore in the app if client uninstall and reinstall after sometime?

<details><summary>See here</summary>

```
   If a client reinstall the app after sometime and he has any active_plan previously then when client arrived at the overview page we will check if he has any previous plan that needs to be restored based on some conditions we will activate its plan by converting it to onetime.

```
</details>



