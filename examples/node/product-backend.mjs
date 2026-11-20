// Framework-neutral backend helper. Store BILLING_SERVICE_TOKEN only on your product server.
export async function requireEntitlement({authenticatedUser,activeOrganisationId,productCode,feature,assertMembership}){
 if(!authenticatedUser)throw new Error('Unauthenticated');
 await assertMembership(authenticatedUser.id,activeOrganisationId);
 const response=await fetch(`${process.env.BILLING_API_URL}/access/check`,{method:'POST',headers:{Authorization:`Bearer ${process.env.BILLING_SERVICE_TOKEN}`,'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({organisation_id:activeOrganisationId,product:productCode}),signal:AbortSignal.timeout(5000)});
 if(!response.ok)throw new Error('Billing verification unavailable or denied');
 const e=await response.json();
 if(e.organisation_id!==activeOrganisationId||e.product!==productCode||!e.allowed||!e.valid_until||Date.parse(e.valid_until)<=Date.now()||(feature&&e.features[feature]!==true))throw new Error('Subscription does not permit this action');
 return e;
}
// In each protected sales/inventory/etc. route: authenticate OIDC session, verify organisation
// membership, then call requireEntitlement BEFORE reading or mutating operational records.
