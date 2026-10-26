package main

import "testing"

func TestBrokerEngineIdentityAndCapability(t *testing.T) {
 cases:=[]struct{input string; engine BrokerEngine; implemented bool}{
  {"postgresql",BrokerPostgreSQL,true},
  {" POSTGRES ",BrokerPostgreSQL,true},
  {"mysql",BrokerMySQL,false},
  {"MariaDB",BrokerMySQL,false},
  {"mssql",BrokerMSSQL,false},
  {"SQLServer",BrokerMSSQL,false},
 }
 for _,tc:=range cases{
  engine,err:=normalizeBrokerEngine(tc.input)
  if err!=nil||engine!=tc.engine{t.Fatalf("%q normalized to %q: %v",tc.input,engine,err)}
  _,err=requireImplementedTemporaryBroker(tc.input)
  if (err==nil)!=tc.implemented{t.Fatalf("%q unexpected implementation availability: %v",tc.input,err)}
 }
 if _,err:=normalizeBrokerEngine("oracle");err==nil{t.Fatal("unknown engine accepted")}
 if _,err:=requireImplementedTemporaryBroker("oracle");err==nil{t.Fatal("unsupported broker accepted")}
}
