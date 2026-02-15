import React from "react";
import "./styles.css"
import { HiOutlineLocationMarker } from "react-icons/hi";
import { Container, Row, Col } from 'react-bootstrap';
import { BsEnvelopeAt } from "react-icons/bs";
import { FiPhoneCall } from "react-icons/fi";
import { ImFacebook2 } from "react-icons/im";
import { BsLinkedin } from "react-icons/bs";
import { FaInstagramSquare } from "react-icons/fa";

const Footer = () => {
    return (
        <footer className="bg-light pt-5 pb-3 border-top">
            <div className="footer-top">
                <Container>
                    <Row>
                        <Col lg={4} className="mb-4">
                            <h4 className="fw-bold mb-3">About us</h4>
                            <p className="text-muted">Welcome to TourKokan, your ultimate guide to exploring the hidden gems of Kokan! Our app showcasing unique places and local attractions. Discover the rich culture, historical sites, and natural beauty of Kokan through our curated content.</p>
                        </Col>

                        <Col md={6} lg={4} className="mb-4">
                            <h4 className="fw-bold mb-3">Information</h4>
                            <ul className="list-unstyled ps-0">
                                <li className="mb-2 d-flex">
                                    <HiOutlineLocationMarker className="me-2 mt-1 flex-shrink-0 text-primary" />
                                    <span>402, Sai Anand CHS, Gopinath Chawk, Dombivali (w), 421202.</span>
                                </li>
                                <li className="mb-2 d-flex align-items-center">
                                    <BsEnvelopeAt className="me-2 text-primary" />
                                    <a href="mailto:support@tourkokan.com" className="text-decoration-none text-dark">support@tourkokan.com</a>
                                </li>
                                <li className="mb-2 d-flex align-items-center">
                                    <FiPhoneCall className="me-2 text-primary" />
                                    <a href="tel:8454029747" className="text-decoration-none text-dark">8454029747</a>
                                </li>
                            </ul>
                        </Col>

                        <Col md={6} lg={4} className="mb-4">
                            <h4 className="fw-bold mb-3">Follow us</h4>
                            <div className="d-flex gap-3">
                                <a href="https://www.facebook.com/people/Tourkokan/61560289596939/?mibextid=LQQJ4d" className="text-dark">
                                    <ImFacebook2 size={24} />
                                </a>
                                <a href="https://www.instagram.com/tour_kokan/" className="text-dark">
                                    <FaInstagramSquare size={26} />
                                </a>
                            </div>
                        </Col>
                    </Row>
                </Container>
            </div>
            <div className="footer-bottom border-top pt-3">
                <Container>
                    <Row>
                        <Col sm={6}>
                            <p className="text-muted small">&copy; {new Date().getFullYear()} TourKokan. All rights reserved.</p>
                        </Col>
                        <Col sm={6}>
                            <ul className="list-inline text-sm-end">
                                <li><a href="Terms">Terms & Conditions</a></li>
                                <li><a href="PrivacyPolicy">Privacy Policy</a></li>
                            </ul>
                        </Col>
                    </Row>
                </Container>
            </div>
        </footer>
    )
}

export default Footer;