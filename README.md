# Manitou SSO Processing Module

This repository contains a Magento 2 module developed to demonstrate technical expertise during job interviews. The module showcases various aspects of Magento 2 module development, including custom helpers, plugins, observers, and SAML (Security Assertion Markup Language) integration.

## Module Purpose
The primary purpose of this module is to illustrate skills in:
- Developing Magento 2 modules  
- Handling SAML2-based Single Sign-On (SSO)  
- Customizing customer group assignments  

### Scenario
Your Magento 2 instance operates multiple storefronts:

A webshop dedicated solely to Manitou products.
A webshop dedicated solely to Gehl products.
A webshop offering both Manitou and Gehl products.

### Requirement
When Manitou dealers log in, their trademark should be identified as Manitou only. Consequently, they should be directed to the Manitou webshop, ensuring that they view and order Manitou products exclusively. 
This setup maintains brand consistency and a streamlined user experience—Manitou employees see only the products relevant to them. Same for Gehl. If there're no trademarks in saml data they get customergroup manitou-gehl and access denied so a responsible person should check with HQ to adjust their group manually and set their access to granted.



## Repository Structure
Since this repository is tailored for simplicity, it only includes the contents of the `ManitouSsoProcessing` directory. Typically, this module would reside under `app/code/Jvdh/ManitouSsoProcessing` in a Magento 2 project.

### Included Components
1. **Helper Classes**  
   - `Helper/Data.php`: Contains reusable methods for SSO processing, such as customer group mapping, module checks, and API key retrieval.

2. **Plugins**  
   - `Plugin/Controller/Saml2/ACS.php`: Extends the ACS (Assertion Consumer Service) controller to manage customer groups, quotes, and SSO logic based on SAML attributes.

## Configuration
The module relies on specific configurations and environment variables for seamless operation:

1. **Environment Variables**  
   - `MANITOU_API_KEY`: An encrypted API key required to query Manitou-specific services.

2. **Magento Admin Settings**  
   - Navigate to `Stores > Configuration > Foobar > SAML2 Customer` to ensure the module is enabled for Manitou integration.
   - Adjust any additional fields as needed (e.g., email sender, copy-to emails) to properly handle SSO notifications.

## Key Features
- **SAML2 Integration**: Utilizes the SAML2 protocol for secure Single Sign-On.
- **Dynamic Customer Group Assignment**: Determines and assigns the correct customer group based on external API data tied to Manitou user attributes.
- **Session and Quote Management**: Ensures that session and quote data are updated when a user’s group changes, maintaining accurate pricing and tax rules.
- **Error Handling**: Sends notification emails and logs out users when group resolution fails, preventing unauthorized access.

## Notes
- This module is primarily a technical demonstration and may require customization for production environments.
- Proper security measures (e.g., storing and managing API keys securely) should be taken when deploying a live SSO solution.

## License
This project is licensed under the MIT License. Feel free to use and modify it as needed.

## Author
Developed by me.

## Contact
For any questions or further discussions, please feel free to contact me via [LinkedIn profile](https://www.linkedin.com/in/jonasvdh/).
