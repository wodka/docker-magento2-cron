<?php

class Mage {
  public static function throwException($msg) {
    die($msg);
  }
}

require "/credis.php";
$redis = new Credis_Client("redis", 6379, null, null, 15);

require "/varnish.php";
$varnish = new Nexcessnet_Turpentine_Model_Varnish_Admin_Socket(array(
	"host" => "varnish",
	"auth_secret" => file_get_contents("/varnish.secret")
));

// get first VCL (there should be always only one)
$vcl = $varnish->vcl_list();
if ($vcl["code"] != Nexcessnet_Turpentine_Model_Varnish_Admin_Socket::CODE_OK) die("Unable to read VCLs from Varnish");
$vcl = explode("\n", trim($vcl["text"]))[0];
$vcl = explode(" ", $vcl);
$vcl = $vcl[sizeof($vcl) - 1];

$vcl = $varnish->vcl_show($vcl);
if ($vcl["code"] != Nexcessnet_Turpentine_Model_Varnish_Admin_Socket::CODE_OK) die("Unable to read latest VCL from Varnish");
$vcl = $vcl["text"];
$vcl = preg_replace("/#AUTOGENERATED_START.*#AUTOGENERATED_END/ms", "#AUTOGENERATED_START\n#AUTOGENERATED_END", $vcl);
$vcl = explode("\n", $vcl);
$apaches = array();
$hosts = array_keys($redis->hGetAll("fb_apache_containers"));
foreach ($hosts as $host) {
  $hostname = "apache_" . str_replace(".", "_", $host);
  $apaches[$hostname] = $host;
}
ksort($apaches);

$hosts_to_add_to_vcl = "";
foreach ($apaches as $name=>$ip) {
  $hosts_to_add_to_vcl .= "backend $name {\n\t.host = \"$ip\";\n\t.port = \"80\";\n}\n";
}

$hosts_to_add_to_vcl .= "sub vcl_init {\n\tnew cluster1 = directors.round_robin();\n";
foreach ($apaches as $name=>$ip) {
  $hosts_to_add_to_vcl .= "\tcluster1.add_backend($name);\n";
}
$hosts_to_add_to_vcl .= "}\n";
$hosts_to_add_to_vcl .= "sub vcl_recv {set req.backend_hint = cluster1.backend();}\n";

$new_vcl = "";
foreach ($vcl as $line) {
  $new_vcl .= "$line\n";
  if ($line == "#AUTOGENERATED_START") {
    $new_vcl .= $hosts_to_add_to_vcl;
  }
}

// send the new VCL
$vcl_name = "autogenated" . time();
$varnish->vcl_inline($vcl_name, $new_vcl);
sleep(1);
$varnish->vcl_use($vcl_name);

// remove all VCL exept the last one
$vcls = $varnish->vcl_list();
if ($vcls["code"] == Nexcessnet_Turpentine_Model_Varnish_Admin_Socket::CODE_OK) {
  $vcls = explode("\n", trim($vcls["text"]));
  array_pop($vcls);
  foreach ($vcls as $vcl_to_remove) {
    $vcl_to_remove = explode(" ", $vcl_to_remove);
    $vcl_to_remove = $vcl_to_remove[sizeof($vcl_to_remove) - 1];
    $varnish->vcl_discard($vcl_to_remove);
  }
}
