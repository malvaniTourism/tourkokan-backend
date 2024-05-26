import Container from 'react-bootstrap/Container';
import Navbar from 'react-bootstrap/Navbar';
import { Form, Button, Nav } from 'react-bootstrap';

function NavigationBar() {
    return (
        <>
            <Navbar className="bg-body-tertiary">
                <Container>
                    <Navbar.Brand href="#home" style={{ display: "flex", alignItems: "center" }}>
                        <img
                            alt=""
                            src="https://tourkokan.com/logo.png"
                            width="70"
                            height="70"
                            className="d-inline-block align-top"
                        />{' '}
                        Tourkokan
                    </Navbar.Brand>
                    <div style={{display: "flex",  justifyContent: "space-evenly", width: "25vw"}}>
                    <Nav.Link href="/">Home</Nav.Link>
                    <Nav.Link href="/#About">About Us</Nav.Link>
                    <Nav.Link href="/#Contact">Contact Us</Nav.Link>
                </div>

                <Form className="d-flex">
                    <Button variant="outline-primary">Download</Button>
                </Form>
            </Container>
        </Navbar >
    </>
  );
}

export default NavigationBar;