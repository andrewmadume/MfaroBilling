<?php
declare(strict_types=1);
final class ProductBillingClient {
 public function __construct(private string $baseUrl,private string $token){}
 public function requireFeature(string $organisationId,string $product,string $feature,callable $assertMembership):array {
  // The product's authenticated session determines organisationId. Never pass an unchecked browser ID.
  $assertMembership($organisationId);
  $c=curl_init(rtrim($this->baseUrl,'/').'/access/check');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','Authorization: Bearer '.$this->token],CURLOPT_POSTFIELDS=>json_encode(['organisation_id'=>$organisationId,'product'=>$product],JSON_THROW_ON_ERROR)]);
  $body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
  if($status!==200||$body===false)throw new RuntimeException('Entitlement verification unavailable');
  $e=json_decode($body,true,512,JSON_THROW_ON_ERROR);
  if(($e['organisation_id']??'')!==$organisationId||($e['product']??'')!==$product||!($e['allowed']??false)||strtotime($e['valid_until']??'')<=time()||!($e['features'][$feature]??false))throw new RuntimeException('Access denied');
  return $e;
 }
}
