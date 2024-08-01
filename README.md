# Woocommerce Wompi Payments InPage Plugin

## Descripción
Este plugin permite integrar la pasarela de pagos Wompi en tu tienda WooCommerce, facilitando a tus clientes realizar pagos de manera segura y eficiente directamente sin salir de tu pagina web.

### Metodos de pago soportados
* Tarjetas de Crédito/Débito
* PSE
* Boton Bancolombia
* Nequi

## Instalación
1. Descarga el plugin desde el repositorio.
2. Sube el archivo ZIP a tu instalación de WordPress desde el panel de administración.
3. Activa el plugin desde la sección de Plugins en WordPress.

## Configuración
1. Debes de tener una cuenta activa en la pasarela de pagos Wompi Colombia
2. Accede a tu cuenta Wompi en [https://comercios.wompi.co/home](https://comercios.wompi.co/home)
3. Ingresa a la seccion de "Desarrolladores"
4. En la opcion "URL de Eventos" ingresar la siguiente URL `https://tusitio.com?wc-api=WC_Wompi_PSE` y presiona el boton "Guardar"
5. Copia las llaves publica y privada, y los secretos de Eventos e Integridad.
6. Navega a la sección de ajustes de WooCommerce.
7. Selecciona la pestaña de pagos e ingresa a PSE (Wompi).
8. En los campos de Llave publica, Llave privada, Eventos e Integridad diligencia los datos con lo anteriormente extraido del paso 5.
9. Guarda los cambios.
10. Nuevamente en la pestaña de pagos, busca los demas metodos de pago Wompi y habilítalos.

## Contribución
1. Bienvenidas

## Licencia
Este proyecto está licenciado bajo la Licencia MIT. Consulta el archivo LICENSE para más detalles.