select r.id
,v1.value txt, v1.property_id pTxt
,v2.value ref, v2.property_id pRef
from resource r
inner join value v1 on v1.property_id = 91 and r.id = v1.resource_id
inner join value v2 on v2.property_id = 35 and r.id = v2.resource_id
where r.resource_class_id = 31


