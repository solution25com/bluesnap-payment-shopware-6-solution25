![426098293-01d94c75-9354-489e-b1d6-9c3502d4a427](https://github.com/user-attachments/assets/9b74c3ea-89e1-4421-82e4-4b3e9673f9fc)

# Bluesnap Payment

## Introduction

The Bluesnap Shopware 6 Plugin is a reliable payment solution that connects Bluesnap’s gateway with your Shopware store. It supports a variety of payment methods such as credit cards, Apple Pay, Google Pay, hosted checkout, and pay-by-link. Merchants can manage full or partial refunds, enable 3D Secure for added safety, and offer customers the option to save, update, or delete their card details. With a clear setup process and secure transactions, the plugin helps businesses offer a smooth and flexible payment experience.

### Key Features

- **Credit Card Capture**: Accept card payments through Bluesnap’s secure gateway.  
- **Hosted Checkout**: Redirect customers to a secure Bluesnap-hosted payment page.  
- **Apple Pay & Google Pay**: Offer fast, device-based payment options.  
- **Pay by Link**: Send customers a link to complete their payment.  
- **Refunds**: Support for full and partial refunds from the order view.  
- **Save Card**: Let customers store card details for future use.  
- **Update Card**: Allow customers to change saved card information.  
- **Delete Card**: Give users control to remove stored cards.  
- **3D Secure**: Add an extra layer of protection to card transactions.  

## Get Started

### Installation & Activation

1. **Download**

- Clone the Plugin Repository:
- Open your terminal and run the following command in your Shopware 6 custom plugins directory (usually located at custom/plugins/):

  ```
  git clone https://github.com/solution25com/bluesnap-payment-shopware-6-solution25.git
  ```

2. **Install the Plugin in Shopware 6**

- Log in to your Shopware 6 Administration panel.
- Navigate to Extensions > My Extensions.
- Locate the newly cloned plugin and click Install.

3. **Activate the Plugin**

- After installation, click Activate to enable the plugin.
- In your Shopware Admin, go to Settings > System > Plugins.
- Upload or install the “Bluesnap” plugin.
- Once installed, toggle the plugin to activate it.

4. **Verify Installation**

- After activation, you will see Bluesnap in the list of installed plugins.
- The plugin name, version, and installation date should appear as shown in the screenshot below.
![426098419-a1b7c065-c0dd-4fd7-9f82-1df99090ac50](https://github.com/user-attachments/assets/a0ee3171-6b69-44ba-b715-9b67039cc8b7)

## Plugin Configuration

1. **Access Plugin Settings**

- Go to Settings > System > Plugins.
- Locate Bluesnap and click the three dots (...) icon or the plugin name to open its settings.

2. **General Settings**
<br>Before using the plugin, configure the API keys and payment settings:
- **API Key for Live**: Required for live transactions.  
- **API Public Key Live**: Public key for authentication in the live environment.  
![426098746-42aed29b-9907-4943-ad3e-041509de8b2c](https://github.com/user-attachments/assets/692fc1eb-dac5-4dd0-ad0b-333fb36e1dfb)

- **API Key for Sandbox**: Required for testing transactions in the sandbox environment.  
- **API Public Key Sandbox**: Public key for authentication in sandbox mode.
![426098648-222b9bc2-3c00-4da2-93ca-35438e9fd085](https://github.com/user-attachments/assets/be0bac98-51fd-4046-998e-c9f02227144f)

- **3D Secure (Activate/Deactivate)**: When enabled, 3D Secure authentication will be required for all credit card transactions.  
![426098839-750186db-9201-4938-8cce-c883e6a610eb](https://github.com/user-attachments/assets/2f6c861b-b657-4403-bcd8-9e5cc3f79762)

- **Vaulted Customer**  

- **Ensure you configure these settings before enabling payment methods in your store.**
![426099035-743f94dc-307b-449f-8607-389db4dac60d](https://github.com/user-attachments/assets/afdb80d8-ce1c-413e-9658-42d31d2e567c)


3. **Save Configuration**

- Click Save in the top-right corner to store your settings.
![426099116-903fbcf6-1776-448f-a10f-a6e219af5ce4](https://github.com/user-attachments/assets/6353045e-481a-4131-a2ba-67df74fcb6ae)


## Features & Usage
### 1. Credit Card Capture

This feature allows customers to complete transactions via Bluesnap’s PCI-compliant payment gateway using their credit card.

**How It Works**:  
Customers enter their credit card details in a secure form.  
The payment is processed via Bluesnap’s gateway.

**Steps**:  
1. Select **"Bluesnap Credit Card"** as the payment method.  
2. Enter the customer’s credit card details into the Bluesnap form.  
3. Submit payment.
![426099916-a14f0c80-bdfe-4643-b02b-fa6d962176a3](https://github.com/user-attachments/assets/0595f91d-a526-483c-9e57-dbe6917170f5)

### 2. Hosted Checkout

Bluesnap’s Hosted Checkout provides a secure checkout page hosted by Bluesnap, removing the need for merchants to handle sensitive payment information directly.

**How It Works**:  
Customers are redirected to a secure hosted checkout page.  
Payments are securely processed through Bluesnap.

**Steps**:  
1. Select **"Bluesnap Hosted Checkout"** as the payment method.  
2. The customer is redirected to the hosted checkout page.  
3. The customer completes the transaction on the hosted page.
![BlueSnap Payment SC5](https://github.com/user-attachments/assets/d498e033-1121-4871-bd6e-78d031f2a748)


### 3. Apple Pay Integration

Bluesnap supports Apple Pay, enabling customers to pay using their Apple devices with a single tap.

**How It Works**:  
Customers use their Apple device to authenticate the transaction via Face ID, Touch ID, or Apple Watch.

**Steps**:  
1. Select **"Apple Pay"** as the payment method.  
2. Confirm payment through Face ID, Touch ID, or an Apple Watch.
![426100163-e1fa5fd4-d397-407f-a0a2-3c1a0d55d890](https://github.com/user-attachments/assets/920dd304-7faf-448f-b003-176d1c440999)

### 4. Google Pay Integration

Customers can use Google Pay to complete transactions securely and quickly.

**How It Works**:  
Customers authenticate the payment using their Google Pay account.

**Steps**:  
1. Select **"Google Pay"** as the payment method.  
2. Authenticate the payment via the customer’s Google Pay account.
![426100221-04de736b-cb0f-4241-a7f1-c822ef3f29c5](https://github.com/user-attachments/assets/92a85870-d314-4914-950d-3432f7bb2098)


### 5. Refunds (Full & Partial)

The Bluesnap plugin supports both full and partial refunds for transactions.

**How It Works**:  
- Full refunds return the entire payment.  
- Partial refunds return a portion of the payment.

**Steps for Full Refund**:  
1. Navigate to the **Orders** section.  
2. Select the order to be refunded.  
3. Click **"Create Refund."**  
4. Change the status to **"In Progress."**
![426100313-dea74cff-6d10-44b0-aa27-c2e1945f1515](https://github.com/user-attachments/assets/71ec6d5a-3512-4053-87fa-163563bfefc0)
![426100367-4a5684b4-edba-4c69-88e3-e6a0891ee864](https://github.com/user-attachments/assets/b0b25bf0-62de-43ca-b7e8-6c9e4815684b)
![426100476-8b664858-a523-4e7e-8d7a-f04179868d9a](https://github.com/user-attachments/assets/7f1f2d9a-5f8f-48c9-a5c6-04ecd9e1ccff)
![426100516-cbc954e6-1e28-4294-8d7a-b63d4f412a0e](https://github.com/user-attachments/assets/6b34e4ec-033e-4702-bbab-535638b4c9dd)

**Steps for Partial Refund**:  
1. Navigate to the **Orders** section.  
2. Select the order to be partially refunded.  
3. Specify the refund amount.  
4. Click **"Create Refund."**  
5. Change the status to **"In Progress."**
![426101045-e4f5af35-8a30-48c9-a960-adae028e3817](https://github.com/user-attachments/assets/950caa9f-2d62-453a-991a-147de9953666)
![426101118-acd8ca06-76f6-420e-ae36-92c0da240a48](https://github.com/user-attachments/assets/47498af4-ed2e-4863-a997-dbc4e5281032)

### 6. Save Card Feature

This feature allows customers to securely save their card details for future transactions.

**How It Works**:  
A Vaulted ID is created to store the customer’s payment details securely.  
Customers can select saved cards for future transactions.

**Steps**:  
1. During checkout, select **"Save my card for future use."**  
2. The card is securely stored and available for future use.

### 7. Update Card Feature

Allows customers to update their saved card details securely for future transactions.

**How It Works**:  
Customers can modify their saved card information directly within the payment gateway.

**Steps**:  
1. Navigate to checkout.  
2. Update the saved card details.  
3. Save the updated card information.

### 8. Delete Card Feature

This feature allows customers to delete their stored card details when they no longer wish to use them.

**How It Works**:  
Customers can remove their stored card information from the system.

**Steps**:  
1. Navigate to checkout.  
2. Select the card to be deleted.  
3. Click **"Delete"** to remove the card from the system.

### 9. 3D Secure Authentication

Enabling 3D Secure adds an extra layer of authentication to credit card transactions, reducing fraud.

**How It Works**:  
After the customer enters their credit card details, they will be redirected to their bank’s authentication page to complete the transaction.

**Steps**:  
1. Enable **3D Secure** in the plugin configuration.  
2. Customers will automatically be prompted for 3D Secure authentication during checkout.

### Best Practices

- **Use Sandbox for Testing**: Always test your payment methods and flows in sandbox mode before going live.  
- **Enable 3D Secure**: Improve fraud protection by turning on 3D Secure for all credit card transactions.  
- **Keep API Keys Secure**: Never share your API keys publicly. Rotate them regularly if needed.  
- **Inform Customers About Saved Cards**: Be transparent about card-saving features and offer easy ways to manage or delete saved cards.  
- **Monitor Transactions**: Regularly check your Bluesnap dashboard to track transactions, monitor refunds, and handle any failed payments.  
- **Stay Updated**: Keep the plugin updated to the latest version to ensure compatibility and security improvements.

### Troubleshooting

**Issue: Payment method not appearing at checkout**  
- Ensure the plugin is properly configured with the correct API keys.  
- Verify that the desired payment methods are activated in your Shopware settings.  
- Check that your store is in the correct mode (sandbox or live) matching the keys.

**Issue: 3D Secure not triggering**  
- Confirm 3D Secure is enabled in the plugin settings.  
- Ensure the credit card used supports 3D Secure.  
- Test with different cards if needed.

**Issue: Saved cards are not displaying**  
- Verify that the **Vaulted Customer** feature is enabled.  
- Ensure the user is logged into their account during checkout.

**Issue: Refund fails or doesn’t reflect in order status**  
- Make sure the transaction was successfully captured before initiating a refund.  
- Confirm you are using the correct order and amount, especially for partial refunds.  
- Check Bluesnap’s dashboard for more details on failed refund attempts.

### FAQ

**What payment methods does the Bluesnap Shopware 6 Plugin support?**  
- The plugin supports Credit Card, Apple Pay, Google Pay, Hosted Checkout, and Pay by Link.

**Can customers save their card details for future use?**  
- Yes, customers can securely save card details and manage them (update or delete) within their account.

**How do I enable 3D Secure?**  
- You can activate 3D Secure in the plugin configuration settings under the API section.

**Is sandbox testing supported?**  
- Yes, the plugin supports sandbox mode for safe testing. Use the Sandbox API keys in the configuration section.

**Can I issue partial refunds?**  
- Yes, both full and partial refunds are supported via the order management interface.

## Wiki Documentation
Read more about the plugin configuration on our [Wiki]().



