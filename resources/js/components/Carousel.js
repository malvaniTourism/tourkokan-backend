import React from 'react';
import Carousel from 'react-bootstrap/Carousel';

function MyCarousel() {
    return (
        <Carousel data-bs-theme="dark">
            <Carousel.Item>
                <img
                    className="d-block w-100"
                    style={{height: "90vh"}}
                    src="https://www.malvancity.com/wp-content/uploads/2020/04/kunkeshwar-temple.jpg"
                    alt="First slide"
                />
                <Carousel.Caption>
                    <h5>First slide label</h5>
                    <p>Nulla vitae elit libero, a pharetra augue mollis interdum.</p>
                </Carousel.Caption>
            </Carousel.Item>
            <Carousel.Item>
                <img
                    className="d-block w-100"
                    style={{height: "90vh"}}
                    src="https://www.malvancity.com/wp-content/uploads/2020/04/kunkeshwar-temple.jpg"
                    alt="Second slide"
                />
                <Carousel.Caption>
                    <h5>Second slide label</h5>
                    <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>
                </Carousel.Caption>
            </Carousel.Item>
            <Carousel.Item>
                <img
                    className="d-block w-100"
                    style={{height: "90vh"}}
                    src="https://www.malvancity.com/wp-content/uploads/2020/04/kunkeshwar-temple.jpg"
                    alt="Third slide"
                />
                <Carousel.Caption>
                    <h5>Third slide label</h5>
                    <p>
                        Praesent commodo cursus magna, vel scelerisque nisl consectetur.
                    </p>
                </Carousel.Caption>
            </Carousel.Item>
        </Carousel>
    );
}

export default MyCarousel;
