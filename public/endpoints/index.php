<?php
header('Content-Type: text/html; charset=utf-8');
?>
<h1>eWeb SOAP Endpoints</h1>
<p>Click an endpoint. If it requires inputs, add them as query string parameters.</p>
<ul>
<li><a href="check-service.php?parameters=">CheckService</a></li>
<li><a href="current-server-date-time.php?parameters=">CurrentServerDateTime</a></li>
<li><a href="get-active-item-by-sku.php?sku=">GetActiveItemBySKU</a></li>
<li><a href="get-active-item-by-stock-num.php?categoryid=&amp;stocknum=">GetActiveItemByStockNum</a></li>
<li><a href="get-active-item-details-by-sku.php?sku=">GetActiveItemDetailsBySKU</a></li>
<li><a href="get-active-item-details-by-stock-num.php?categoryid=&amp;stocknum=">GetActiveItemDetailsByStockNum</a></li>
<li><a href="get-active-item-details.php?searchby=">GetActiveItemDetails</a></li>
<li><a href="get-active-item-details-sorted.php?searchby=&amp;sortby=">GetActiveItemDetailsSorted</a></li>
<li><a href="get-active-item-qohby-sku.php?sku=">GetActiveItemQOHBySKU</a></li>
<li><a href="get-active-item-qohby-stock-num.php?categoryid=&amp;stocknum=">GetActiveItemQOHByStockNum</a></li>
<li><a href="get-active-items-by-category.php?categoryid=">GetActiveItemsByCategory</a></li>
<li><a href="get-active-items-by-category-sorted.php?categoryid=&amp;sortby=">GetActiveItemsByCategorySorted</a></li>
<li><a href="get-active-items-priced-by-group.php?searchby=&amp;priceparams=">GetActiveItemsPricedByGroup</a></li>
<li><a href="get-active-items-qoh.php?searchby=">GetActiveItemsQOH</a></li>
<li><a href="get-active-items-qohsorted.php?searchby=&amp;sortby=">GetActiveItemsQOHSorted</a></li>
<li><a href="get-active-items.php?searchby=">GetActiveItems</a></li>
<li><a href="get-active-items-sorted.php?searchby=&amp;sortby=">GetActiveItemsSorted</a></li>
<li><a href="get-all-active-item-details.php">GetAllActiveItemDetails</a></li>
<li><a href="get-all-active-item-details-sorted.php?sortby=">GetAllActiveItemDetailsSorted</a></li>
<li><a href="get-all-active-items-qoh.php">GetAllActiveItemsQOH</a></li>
<li><a href="get-all-active-items-qohsorted.php?sortby=">GetAllActiveItemsQOHSorted</a></li>
<li><a href="get-all-active-items.php">GetAllActiveItems</a></li>
<li><a href="get-all-active-items-sorted.php?sortby=">GetAllActiveItemsSorted</a></li>
<li><a href="get-all-brands.php">GetAllBrands</a></li>
<li><a href="get-all-brands-sorted.php?sortby=">GetAllBrandsSorted</a></li>
<li><a href="get-all-categories.php">GetAllCategories</a></li>
<li><a href="get-all-categories-sorted.php?sortby=">GetAllCategoriesSorted</a></li>
<li><a href="get-all-customers.php">GetAllCustomers</a></li>
<li><a href="get-all-deleted-items.php">GetAllDeletedItems</a></li>
<li><a href="get-all-deleted-items-sorted.php?sortby=">GetAllDeletedItemsSorted</a></li>
<li><a href="get-all-lists.php">GetAllLists</a></li>
<li><a href="get-all-stores.php">GetAllStores</a></li>
<li><a href="get-all-stores-sorted.php?sortby=">GetAllStoresSorted</a></li>
<li><a href="get-all-vendors.php">GetAllVendors</a></li>
<li><a href="get-all-vendors-sorted.php?sortby=">GetAllVendorsSorted</a></li>
<li><a href="get-brand-by-id.php?brandid=">GetBrandByID</a></li>
<li><a href="get-brands.php?searchby=">GetBrands</a></li>
<li><a href="get-brands-sorted.php?searchby=&amp;sortby=">GetBrandsSorted</a></li>
<li><a href="get-categories.php?searchby=">GetCategories</a></li>
<li><a href="get-categories-sorted.php?searchby=&amp;sortby=">GetCategoriesSorted</a></li>
<li><a href="get-category-by-id.php?categoryid=">GetCategoryByID</a></li>
<li><a href="get-customer-by-id.php?customerid=">GetCustomerByID</a></li>
<li><a href="get-customers.php?searchby=">GetCustomers</a></li>
<li><a href="get-customers-sorted.php?searchby=&amp;sortby=">GetCustomersSorted</a></li>
<li><a href="get-deleted-item-by-sku.php?sku=">GetDeletedItemBySKU</a></li>
<li><a href="get-deleted-item-by-stock-num.php?categoryid=&amp;stocknum=">GetDeletedItemByStockNum</a></li>
<li><a href="get-deleted-items.php?searchby=">GetDeletedItems</a></li>
<li><a href="get-deleted-items-sorted.php?searchby=&amp;sortby=">GetDeletedItemsSorted</a></li>
<li><a href="get-group-customers-by-status.php?active=">GetGroupCustomersByStatus</a></li>
<li><a href="get-group-customers.php">GetGroupCustomers</a></li>
<li><a href="get-groups-by-customer.php?customerkey=">GetGroupsByCustomer</a></li>
<li><a href="get-item-image-by-sku.php?sku=&amp;imageindex=">GetItemImageBySKU</a></li>
<li><a href="get-item-image.php?categoryid=&amp;stocknum=&amp;imageindex=">GetItemImage</a></li>
<li><a href="get-item-image-urlby-sku.php?sku=&amp;imageindex=">GetItemImageURLBySKU</a></li>
<li><a href="get-item-image-url.php?categoryid=&amp;stocknum=&amp;imageindex=">GetItemImageURL</a></li>
<li><a href="get-item-images-by-sku.php?sku=">GetItemImagesBySKU</a></li>
<li><a href="get-item-images.php?categoryid=&amp;stocknum=">GetItemImages</a></li>
<li><a href="get-last-upload-date-time.php">GetLastUploadDateTime</a></li>
<li><a href="get-list-by-name.php?listname=">GetListByName</a></li>
<li><a href="get-store-by-id.php?storeid=">GetStoreByID</a></li>
<li><a href="get-stores.php?searchby=">GetStores</a></li>
<li><a href="get-stores-sorted.php?searchby=&amp;sortby=">GetStoresSorted</a></li>
<li><a href="get-vendor-by-id.php?vendorid=">GetVendorByID</a></li>
<li><a href="get-vendors.php?searchby=">GetVendors</a></li>
<li><a href="get-vendors-sorted.php?searchby=&amp;sortby=">GetVendorsSorted</a></li>
<li><a href="is-item-image-newer-by-sku.php?sku=&amp;imageindex=&amp;comparedate=">IsItemImageNewerBySKU</a></li>
<li><a href="is-item-image-newer.php?categoryid=&amp;stocknum=&amp;imageindex=&amp;comparedate=">IsItemImageNewer</a></li>
<li><a href="test.php?echostring=">Test</a></li>
<li><a href="upload-test-web-order.php?ordertoupload=">UploadTestWebOrder</a></li>
<li><a href="upload-web-order.php?ordertoupload=">UploadWebOrder</a></li>
</ul>
