#!/usr/bin/env python3
"""Check proposed contract against the repository schema, not a live database."""
import pathlib, re
root = pathlib.Path(__file__).resolve().parents[2]
schema = (root / 'website/DATABASE-SCHEMA.sql').read_text()
match = re.search(r'CREATE TABLE `shipments`\s*\((.*?)\) ENGINE=', schema, re.S)
assert match, 'shipment schema declaration missing'
decl = match.group(1)
for field in ('id', 'service_type', 'status', 'is_public'):
    assert re.search(r'`' + field + r'`\s', decl), field
statuses = re.search(r'`status`\s+ENUM\((.*?)\)', decl, re.S).group(1)
services = re.search(r'`service_type`\s+ENUM\((.*?)\)', decl, re.S).group(1)
for relative in ('website/includes/shipments/ShipmentMetadataContract.php', 'crm_derakht_sepid/includes/site_shipment_metadata_contract.php'):
    text = (root / relative).read_text()
    for enum in (statuses, services):
        for item in re.findall(r"'([^']+)'", enum):
            assert "'" + item + "'" in text, (relative, item)
print('PASS proposed shipment metadata fields and enums match repository schema; no live database checked')
