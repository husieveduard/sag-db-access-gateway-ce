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
  {"OracleDB",BrokerOracle,false},
 }
 for _,tc:=range cases{
  engine,err:=normalizeBrokerEngine(tc.input)
  if err!=nil||engine!=tc.engine{t.Fatalf("%q normalized to %q: %v",tc.input,engine,err)}
  _,err=requireImplementedTemporaryBroker(tc.input)
  if (err==nil)!=tc.implemented{t.Fatalf("%q unexpected implementation availability: %v",tc.input,err)}
 }
 if _,err:=normalizeBrokerEngine("unknown_db");err==nil{t.Fatal("unknown engine accepted")}
 if _,err:=requireImplementedTemporaryBroker("oracle");err==nil{t.Fatal("unsupported broker accepted")}
}

func TestBrokerCapabilityMatrix(t *testing.T) {
    cases := []struct {
        name string
        engine BrokerEngine
        passthrough bool
        temporary bool
        audit bool
    }{
        {"postgres", BrokerPostgreSQL, true, true, true},
        {"MariaDB", BrokerMySQL, true, false, false},
        {"mysql", BrokerMySQL, true, false, false},
        {"sqlserver", BrokerMSSQL, true, false, false},
        {"oracle", BrokerOracle, false, false, false},
    }
    for _, tc := range cases {
        c, err := brokerCapability(tc.name)
        if err != nil { t.Fatalf("%s: %v", tc.name, err) }
        if c.Engine != tc.engine || c.Passthrough != tc.passthrough || c.TemporaryBroker != tc.temporary || c.SQLAudit != tc.audit {
            t.Fatalf("%s: unexpected capability: %+v", tc.name, c)
        }
        if c.CredentialVaultIPC { t.Fatalf("%s: unimplemented vault transport enabled", tc.name) }
    }
    if _, err := brokerCapability("nonsense"); err == nil { t.Fatal("unknown engine accepted") }
}
