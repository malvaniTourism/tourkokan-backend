import React from "react";
import {Container, Row, Col, ListGroup, Card} from 'react-bootstrap';
// import { Form, Button, Nav, Modal, Row, Col } from 'react-bootstrap';

const About = () => {
    return (
        <Container class="my-5">
            <Row>
                <Col>
                    <h1 class="text-center">About Us</h1>
                    <p>Welcome to <strong>TourKokan</strong>, your ultimate guide to exploring the breathtaking beauty and cultural richness of the Konkan region. Our app is designed to provide you with all the resources you need to make your journey through Konkan an unforgettable experience.</p>

                    <h2>Discover Konkan</h2>
                    <p>The Konkan coast, stretching along the western shores of India, is a treasure trove of natural wonders, historical landmarks, and vibrant culture. From pristine beaches and lush green landscapes to ancient temples and bustling local markets, Konkan has something for every traveler. With TourKokan, you can explore popular destinations like Devgad Beach, Malvan, Ratnagiri, and many more hidden gems.</p>

                    <h2>Resources Available in Konkan</h2>
                    <p>TourKokan offers a wealth of resources to enhance your travel experience:</p>
                    <ListGroup>
                        <ListGroup.Item><strong>Detailed Information:</strong> Comprehensive guides on various tourist spots, including historical sites, natural attractions, and cultural hotspots.</ListGroup.Item>
                        <ListGroup.Item><strong>Bus Timetables:</strong> Up-to-date bus schedules and routes to help you navigate the region with ease.</ListGroup.Item>
                        <ListGroup.Item><strong>Accommodation:</strong> Listings of the best places to stay, from luxury resorts to budget-friendly homestays.</ListGroup.Item>
                        <ListGroup.Item><strong>Local Cuisine:</strong> Recommendations for must-try local dishes and eateries.</ListGroup.Item>
                        <ListGroup.Item><strong>Events and Festivals:</strong> Information on local events, fairs, and festivals to immerse yourself in the local culture.</ListGroup.Item>
                    </ListGroup>

                    <h2>Features of the App</h2>
                    <p>Our app is packed with features to ensure you have all the information at your fingertips:</p>
                    <ListGroup>
                        <ListGroup.Item><strong>Interactive Maps:</strong> Find your way around with our detailed, interactive maps.</ListGroup.Item>
                        <ListGroup.Item><strong>Itinerary Planner:</strong> Plan your trip with ease using our customizable itinerary planner.</ListGroup.Item>
                        <ListGroup.Item><strong>Real-Time Updates:</strong> Stay informed with real-time updates on bus schedules, events, and more.</ListGroup.Item>
                        <ListGroup.Item><strong>User Reviews:</strong> Read reviews and recommendations from fellow travelers.</ListGroup.Item>
                    </ListGroup>

                    <h2>Our Founders</h2>
                    <p>TourKokan was founded by a group of passionate travel enthusiasts who share a deep love for the Konkan region. Our founders, <strong>[Founder 1]</strong>, <strong>[Founder 2]</strong>, and <strong>[Founder 3]</strong>, have extensive backgrounds in tourism, technology, and local culture. Their vision is to make Konkan accessible to travelers from all over the world, providing them with an authentic and enriching travel experience.</p>
                    <Row>
                        <Col md={4}>
                            <Card class="mb-4">
                                <Card.Body>
                                    <Card.Title>[Founder 1]</Card.Title>
                                    <Card.Text>An avid traveler and tourism expert with over 15 years of experience in the industry. [Founder 1] has a deep understanding of Konkan's unique attractions and cultural heritage.</Card.Text>
                                </Card.Body>
                            </Card>
                        </Col>
                        <Col md={4}>
                            <Card class="mb-4">
                                <Card.Body>
                                    <Card.Title>[Founder 2]</Card.Title>
                                    <Card.Text>A tech wizard who has developed several successful travel apps. [Founder 2] ensures that TourKokan is user-friendly and technologically advanced.</Card.Text>
                                </Card.Body>
                            </Card>
                        </Col>
                        <Col md={4}>
                            <Card class="mb-4">
                                <Card.Body>
                                    <Card.Title>[Founder 3]</Card.Title>
                                    <Card.Text>A local resident and cultural ambassador of Konkan. [Founder 3] brings invaluable insights into the region's traditions, festivals, and hidden gems.</Card.Text>
                                </Card.Body>
                            </Card>
                        </Col>
                    </Row>
                    <p>At TourKokan, we are dedicated to providing you with the best travel experience possible. Whether you're a first-time visitor or a seasoned traveler, our app is your go-to resource for exploring the enchanting Konkan coast.</p>
                    <p>Thank you for choosing TourKokan. We look forward to being a part of your journey!</p>
                </Col>
            </Row>
        </Container>
    )
}

export default About;