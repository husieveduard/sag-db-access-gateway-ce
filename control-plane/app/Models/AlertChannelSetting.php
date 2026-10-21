<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AlertChannelSetting extends Model {
 protected $primaryKey='channel';
 protected $keyType='string';
 public $incrementing=false;
 protected $fillable=['channel','enabled','settings'];
 protected $hidden=['settings'];
 protected function casts(): array { return ['enabled'=>'boolean','settings'=>'encrypted:array']; }
}
