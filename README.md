# NetBox Reports Dashboard

Interface web simples para acesso rápido a relatórios e ferramentas de verificação do NetBox, com foco em VMs e IPAM.  
O projeto utiliza **HTML**, **CSS (Bootstrap)** e **JavaScript** puro, sem dependências complexas.

## Funcionalidades

- **Tela de login** com validação simples e redirecionamento.
- **Painel de relatórios** para:
  - VMs não cadastradas no NetBox
  - Hostnames divergentes
  - VMs com nomes duplicados
  - Campos obrigatórios (Site, IP, Role) vazios
  - Consistência de IPs públicos x DNS
  - Detecção de IPs duplicados no IPAM
- Integração com links externos para scripts PHP de verificação.

## Estrutura de Arquivos

- **index.html** → Tela de login  
- **report_vm.html** → Página principal (painel de relatórios)  
- **verifica_vm_netbox.php** → Verifica Máquinas detectadas na rede, mas não cadastradas no Netbox  
- **verifica_hostname_ip.php** → Verifica Hostname divergente  
- **verifica_duplicados.php** → Verifica VMs com nomes duplicados  
- **verifica_site.php** → Verifica Campo *Site* vazio  
- **verifica_ip.php** → Verifica Campo *IP address* vazio  
- **verifica_role.php** → Verifica Campo *Role* vazio  
- **dns_check.php** → Verifica consistência de IPs Públicos do Netbox x DNS  
- **ipam_duplicate.php** → Verifica IPs duplicados no IPAM

## Tecnologias usadas

- [Bootstrap 5.3](https://getbootstrap.com/)
- [Bootstrap Icons](https://icons.getbootstrap.com/)
- JavaScript
