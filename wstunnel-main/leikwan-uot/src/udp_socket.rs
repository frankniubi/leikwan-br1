use std::net::SocketAddr;

use socket2::{Domain, Protocol, Socket, Type};
use tokio::net::UdpSocket;

pub fn bind_udp_socket(address: SocketAddr, buffer_bytes: usize) -> std::io::Result<UdpSocket> {
    let domain = match address {
        SocketAddr::V4(_) => Domain::IPV4,
        SocketAddr::V6(_) => Domain::IPV6,
    };
    let socket = Socket::new(domain, Type::DGRAM, Some(Protocol::UDP))?;
    socket.set_nonblocking(true)?;
    socket.set_recv_buffer_size(buffer_bytes)?;
    socket.set_send_buffer_size(buffer_bytes)?;
    socket.bind(&address.into())?;
    UdpSocket::from_std(socket.into())
}

#[cfg(test)]
mod tests {
    use super::bind_udp_socket;

    #[tokio::test]
    async fn binds_an_ephemeral_udp_socket_with_a_requested_buffer() {
        let socket =
            bind_udp_socket("127.0.0.1:0".parse().unwrap(), 256 * 1024).expect("udp socket binds");

        assert_ne!(socket.local_addr().expect("local address").port(), 0);
    }
}
