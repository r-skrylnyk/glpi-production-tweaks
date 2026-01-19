## File: `src/Computer.php`

### Goal
Simplify the "Computer" asset view by hiding irrelevant tabs for our workflow.
Modified method: `public function defineTabs($options = [])`.

###  Visibility Status
Based on the current configuration, here is the breakdown of tabs:

## Visible (Active)
These tabs are **enabled** for technicians:
- **Default Main Tab** (General Info)
- **Components** (`Item_Devices`) - RAM, CPU, GPU, etc.
- **Software** (`Item_SoftwareVersion`)
- **Running Processes** (`Item_Process`)
- **Environment** (`Item_Environment`)
- **Remote Management** (`Item_RemoteManagement`) - TeamViewer/AnyDesk info
- **Financial Info** (`Infocom`) - Warranty, price
- **Documents** (`Document_Item`)
- **Tickets** (`Item_Ticket`)
- **Locks** (`Lock`)
- **Notes** (`Notepad`)
- **History** (`Log`) - Audit log
- **Rule Logs** (`RuleMatchedLog`)

## Hidden (Commented Out)
These tabs are **disabled** in code to reduce clutter:
- **Impact Analysis** (`addImpactTab`)
- **Operating System** (`Item_OperatingSystem`) *(Managed via Inventory)*
- **Network Ports** (`NetworkPort`) *(Managed via Inventory)*
- **Sockets** (`Socket`)
- **Hard Drives** (`Item_Disk`) *(Managed via Inventory)*
- **Peripherals** (`Asset_PeripheralAsset`)
- **Contracts** (`Contract_Item`)
- **Virtual Machines** (`ItemVirtualMachine`)
- **Antivirus** (`ItemAntivirus`)
- **Knowledge Base** (`KnowbaseItem_Item`)
- **Problems** (`Item_Problem`)
- **Changes** (`Change_Item`)
- **Projects** (`Item_Project`)
- **Certificates** (`Certificate_Item`)
- **Reservations** (`Reservation`)
- **Domains** (`Domain_Item`)
- **Appliances** (`Appliance_Item`)
- **Databases** (`DatabaseInstance`)

### How to customize
To restore any tab, open `src/Computer.php` and uncomment the corresponding line (remove `//`).

**Example - Restoring Antivirus tab:**
Change:
php

// ->addStandardTab(ItemAntivirus::class, $ong, $options) //

Back to original:
php

->addStandardTab(ItemAntivirus::class, $ong, $options)


## Start. Don't forget to make the script executable:

chmod +x scripts/deploy_mods.sh

### Run the deploy script (adjust paths inside the script first):

sudo ./scripts/deploy_mods.sh